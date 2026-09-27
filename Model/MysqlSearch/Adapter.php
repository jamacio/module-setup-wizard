<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Model\MysqlSearch;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Group;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Api\AttributeValue;
use Magento\Framework\Api\Search\Document;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Search\AdapterInterface;
use Magento\Framework\Search\Request\Filter\BoolExpression as BoolFilter;
use Magento\Framework\Search\Request\Filter\Range;
use Magento\Framework\Search\Request\Filter\Term;
use Magento\Framework\Search\Request\Filter\Wildcard;
use Magento\Framework\Search\Request\FilterInterface;
use Magento\Framework\Search\Request\Query\BoolExpression as BoolQuery;
use Magento\Framework\Search\Request\Query\Filter as FilteredQuery;
use Magento\Framework\Search\Request\Query\MatchQuery;
use Magento\Framework\Search\Request\QueryInterface;
use Magento\Framework\Search\RequestInterface;
use Magento\Framework\Search\Response\Aggregation;
use Magento\Framework\Search\Response\Bucket;
use Magento\Framework\Search\Response\QueryResponse;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Search adapter of the mysql_basic engine: answers catalog search requests with SQL on the
 * catalog tables and the indexes Magento keeps in MySQL, instead of OpenSearch/Elasticsearch.
 *
 * Supported: category listing (catalog_category_product_index, so anchor categories and positions
 * behave as usual), store/website, status, visibility, stock, text search on name and SKU (every
 * word must match), price range, attribute filters, SKU/ID filters, sorting by position, name,
 * price and ID, and pagination. Not supported: relevance ranking and facet counts; one empty
 * bucket is returned per requested aggregation, so layered navigation shows no filters.
 */
class Adapter implements AdapterInterface
{
    private const MAX_SEARCH_WORDS = 10;

    private int $aliasCounter = 0;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly MetadataPool $metadataPool,
        private readonly EavConfig $eavConfig,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly TableMaintainer $categoryIndex
    ) {
    }

    public function query(RequestInterface $request)
    {
        $criteria = ['categories' => [], 'visibility' => [], 'words' => [], 'terms' => [], 'ranges' => [], 'wildcards' => []];
        $this->collectQuery($request->getQuery(), $criteria);

        $dimension = $request->getDimensions()['scope'] ?? null;
        $store = $this->storeManager->getStore($dimension ? $dimension->getValue() : null);

        $select = $this->buildSelect((int) $store->getId(), (int) $store->getWebsiteId(), $criteria, $request);
        $connection = $this->resource->getConnection();

        $total = (int) $connection->fetchOne(
            $connection->select()->from(['matches' => (clone $select)->reset(Select::ORDER)], new \Zend_Db_Expr('COUNT(*)'))
        );
        $select->limit((int) $request->getSize(), (int) $request->getFrom());
        $ids = $total > 0 ? $connection->fetchCol($select) : [];

        $documents = [];
        foreach ($ids as $position => $id) {
            $documents[] = new Document([
                Document::ID => (int) $id,
                Document::CUSTOM_ATTRIBUTES => [
                    'score' => new AttributeValue([
                        AttributeValue::ATTRIBUTE_CODE => 'score',
                        AttributeValue::VALUE => count($ids) - $position,
                    ]),
                ],
            ]);
        }

        $buckets = [];
        foreach ($request->getAggregation() as $aggregation) {
            $buckets[$aggregation->getName()] = new Bucket($aggregation->getName(), []);
        }

        return new QueryResponse($documents, new Aggregation($buckets), $total);
    }

    /**
     * Walks the request tree (see etc/search_request.xml of Magento_CatalogSearch and
     * Magento_CatalogGraphQl). Placeholders that were not bound are already removed.
     * "must" and "should" clauses are both treated as required; "must not" is ignored.
     */
    private function collectQuery(QueryInterface $query, array &$criteria): void
    {
        if ($query instanceof BoolQuery) {
            foreach (array_merge($query->getMust(), $query->getShould()) as $child) {
                $this->collectQuery($child, $criteria);
            }
        } elseif ($query instanceof MatchQuery) {
            $words = preg_split('/\s+/u', trim((string) $query->getValue()), -1, PREG_SPLIT_NO_EMPTY);
            $criteria['words'] = array_slice(array_unique(array_merge($criteria['words'], $words)), 0, self::MAX_SEARCH_WORDS);
        } elseif ($query instanceof FilteredQuery) {
            $reference = $query->getReference();
            $query->getReferenceType() === FilteredQuery::REFERENCE_QUERY
                ? $this->collectQuery($reference, $criteria)
                : $this->collectFilter($reference, $criteria);
        }
    }

    private function collectFilter(FilterInterface $filter, array &$criteria): void
    {
        if ($filter instanceof BoolFilter) {
            foreach (array_merge($filter->getMust(), $filter->getShould()) as $child) {
                $this->collectFilter($child, $criteria);
            }
        } elseif ($filter instanceof Term) {
            $values = array_values(array_filter((array) $filter->getValue(), static fn ($v) => $v !== '' && $v !== null));
            match ($filter->getField()) {
                'category_ids' => $criteria['categories'] = array_merge($criteria['categories'], array_map('intval', $values)),
                'visibility' => $criteria['visibility'] = array_merge($criteria['visibility'], array_map('intval', $values)),
                default => $criteria['terms'][$filter->getField()] = $values,
            };
        } elseif ($filter instanceof Range) {
            $criteria['ranges'][$filter->getField()] = [$filter->getFrom(), $filter->getTo()];
        } elseif ($filter instanceof Wildcard) {
            $criteria['wildcards'][$filter->getField()] = (string) $filter->getValue();
        }
    }

    private function buildSelect(int $storeId, int $websiteId, array $criteria, RequestInterface $request): Select
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['e' => $this->resource->getTableName('catalog_product_entity')], ['entity_id'])
            ->join(
                ['website' => $this->resource->getTableName('catalog_product_website')],
                $connection->quoteInto('website.product_id = e.entity_id AND website.website_id = ?', $websiteId),
                []
            )
            ->group('e.entity_id');

        $select->where($this->storeValue($select, 'status', $storeId) . ' = ?', 1);
        if ($criteria['visibility'] !== []) {
            $select->where($this->storeValue($select, 'visibility', $storeId) . ' IN (?)', $criteria['visibility']);
        }

        $position = null;
        if ($criteria['categories'] !== []) {
            // Same index the storefront uses without a search engine: it already expands anchor categories.
            $select->join(
                ['category' => $this->categoryIndex->getMainTable($storeId)],
                'category.product_id = e.entity_id',
                []
            )->where('category.category_id IN (?)', array_unique($criteria['categories']));
            $position = new \Zend_Db_Expr('MIN(category.position)');
        }

        if (!$this->scopeConfig->isSetFlag('cataloginventory/options/show_out_of_stock')) {
            $select->join(
                ['stock' => $this->resource->getTableName('cataloginventory_stock_status')],
                'stock.product_id = e.entity_id AND stock.website_id = 0 AND stock.stock_status = 1',
                []
            );
        }

        $name = null;
        if ($criteria['words'] !== []) {
            $name = $this->storeValue($select, 'name', $storeId);
            foreach ($criteria['words'] as $word) {
                $like = '%' . addcslashes($word, '%_\\') . '%';
                $select->where("({$name} LIKE ? OR e.sku LIKE ?)", $like);
            }
        }

        $price = null;
        foreach ($criteria['ranges'] as $field => [$from, $to]) {
            if ($field === 'price') {
                $column = $price ??= $this->joinPrice($select, $websiteId);
            } else {
                $column = $this->storeValue($select, $field, $storeId);
            }
            if ($column === null) {
                continue;
            }
            if ($from !== null && $from !== '') {
                $select->where("{$column} >= ?", (float) $from);
            }
            if ($to !== null && $to !== '') {
                $select->where("{$column} <= ?", (float) $to);
            }
        }

        foreach ($criteria['terms'] as $field => $values) {
            if ($values === []) {
                continue;
            }
            match ($field) {
                'sku' => $select->where('e.sku IN (?)', $values),
                'entity_id', 'id' => $select->where('e.entity_id IN (?)', array_map('intval', $values)),
                default => $this->filterByAttribute($select, $field, $values, $storeId),
            };
        }

        foreach ($criteria['wildcards'] as $field => $value) {
            $column = $field === 'sku' ? 'e.sku' : $this->storeValue($select, $field, $storeId);
            if ($column !== null) {
                $select->where("{$column} LIKE ?", str_replace('*', '%', addcslashes($value, '%_\\')));
            }
        }

        // Rows are grouped by product, so every sort expression is aggregated (ONLY_FULL_GROUP_BY safe).
        foreach ($this->sortOrders($request) as $field => $direction) {
            if (in_array($field, ['relevance', '_score'], true)) {
                // No relevance score: exact SKU first, then names starting with the first word.
                if ($criteria['words'] !== []) {
                    $name ??= $this->storeValue($select, 'name', $storeId);
                    $select->order(new \Zend_Db_Expr(sprintf('MAX(e.sku = %s) DESC', $connection->quote(implode(' ', $criteria['words'])))));
                    $select->order(new \Zend_Db_Expr(sprintf(
                        'MAX(%s LIKE %s) DESC',
                        $name,
                        $connection->quote(addcslashes($criteria['words'][0], '%_\\') . '%')
                    )));
                }
                continue;
            }
            $expression = match ($field) {
                'position' => $position,
                'name' => 'MIN(' . ($name ??= $this->storeValue($select, 'name', $storeId)) . ')',
                'price' => 'MIN(' . ($price ??= $this->joinPrice($select, $websiteId)) . ')',
                'entity_id', 'id' => 'e.entity_id',
                default => null,
            };
            if ($expression !== null) {
                $select->order(new \Zend_Db_Expr($expression . ' ' . $direction));
            }
        }
        $select->order('e.entity_id ASC');

        return $select;
    }

    /**
     * The request builder normalizes sort orders to [['field' => ..., 'direction' => ...], ...]
     * (storefront and GraphQL); [field => direction] and SortOrder objects are accepted too.
     *
     * @return array<string, string>
     */
    private function sortOrders(RequestInterface $request): array
    {
        $orders = [];
        $sort = method_exists($request, 'getSort') ? (array) $request->getSort() : [];
        foreach ($sort as $key => $order) {
            [$field, $direction] = match (true) {
                $order instanceof SortOrder => [$order->getField(), $order->getDirection()],
                is_array($order) => [$order['field'] ?? null, $order['direction'] ?? 'ASC'],
                default => [$key, $order],
            };
            if (is_string($field) && $field !== '' && !isset($orders[$field])) {
                $orders[$field] = strtoupper((string) $direction) === 'DESC' ? 'DESC' : 'ASC';
            }
        }

        return $orders;
    }

    /**
     * Select/multiselect attributes use the EAV index (what layered navigation filters on);
     * other attributes compare the stored value.
     */
    private function filterByAttribute(Select $select, string $code, array $values, int $storeId): void
    {
        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
        if (!$attribute || !$attribute->getId()) {
            return;
        }

        if (in_array($attribute->getFrontendInput(), ['select', 'multiselect', 'boolean'], true)) {
            $alias = 'eav_' . $this->aliasCounter++;
            $select->join(
                [$alias => $this->resource->getTableName('catalog_product_index_eav')],
                sprintf('%1$s.entity_id = e.entity_id AND %1$s.attribute_id = %2$d AND %1$s.store_id = %3$d', $alias, $attribute->getId(), $storeId),
                []
            )->where("{$alias}.value IN (?)", $values);
            return;
        }

        $column = $this->storeValue($select, $code, $storeId);
        if ($column !== null) {
            $select->where("{$column} IN (?)", $values);
        }
    }

    /**
     * Joins an attribute with the store value falling back to the default (admin) value.
     *
     * @return string|null SQL expression of the value, or null for unknown/static attributes.
     */
    private function storeValue(Select $select, string $code, int $storeId): ?string
    {
        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
        if (!$attribute || !$attribute->getId()) {
            return null;
        }
        if ($attribute->isStatic()) {
            return 'e.' . $this->resource->getConnection()->quoteIdentifier($code);
        }

        $link = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $table = $attribute->getBackendTable();
        $default = 'attr_' . $this->aliasCounter++;
        $scoped = 'attr_' . $this->aliasCounter++;
        $condition = '%1$s.%2$s = e.%2$s AND %1$s.attribute_id = %3$d AND %1$s.store_id = %4$d';
        $select
            ->joinLeft([$default => $table], sprintf($condition, $default, $link, $attribute->getId(), 0), [])
            ->joinLeft([$scoped => $table], sprintf($condition, $scoped, $link, $attribute->getId(), $storeId), []);

        return "COALESCE({$scoped}.value, {$default}.value)";
    }

    private function joinPrice(Select $select, int $websiteId): string
    {
        $select->joinLeft(
            ['price' => $this->resource->getTableName('catalog_product_index_price')],
            sprintf(
                'price.entity_id = e.entity_id AND price.website_id = %d AND price.customer_group_id = %d',
                $websiteId,
                Group::NOT_LOGGED_IN_ID
            ),
            []
        );

        return 'price.min_price';
    }
}
