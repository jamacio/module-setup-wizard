<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Model\MysqlSearch;

use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogSearch\Model\ResourceModel\EngineInterface;

/**
 * Catalog search engine resource of the mysql_basic engine.
 */
class Engine implements EngineInterface
{
    public function __construct(private readonly Visibility $visibility)
    {
    }

    public function getAllowedVisibility()
    {
        return $this->visibility->getVisibleInSiteIds();
    }

    public function allowAdvancedIndex()
    {
        return false;
    }

    public function processAttributeValue($attribute, $value)
    {
        return $value;
    }

    public function prepareEntityIndex($index, $separator = ' ')
    {
        return $index;
    }

    public function isAvailable()
    {
        return true;
    }
}
