<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Model\MysqlSearch;

use Magento\AdvancedSearch\Model\SuggestedQueriesInterface;
use Magento\Search\Model\QueryInterface;

/**
 * Search suggestions of the mysql_basic engine: none (there is no query log analysis).
 */
class Suggestions implements SuggestedQueriesInterface
{
    public function getItems(QueryInterface $query)
    {
        return [];
    }

    public function isResultsCountEnabled()
    {
        return false;
    }
}
