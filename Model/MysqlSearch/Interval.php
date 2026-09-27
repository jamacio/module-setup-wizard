<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Model\MysqlSearch;

use Magento\Framework\Search\Dynamic\IntervalInterface;

/**
 * Price intervals of the mysql_basic engine: always empty.
 */
class Interval implements IntervalInterface
{
    public function load($limit, $offset = null, $lower = null, $upper = null)
    {
        return [];
    }

    public function loadPrevious($data, $index, $lower = null)
    {
        return false;
    }

    public function loadNext($data, $rightIndex, $upper = null)
    {
        return false;
    }
}
