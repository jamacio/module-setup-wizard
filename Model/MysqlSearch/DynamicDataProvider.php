<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Model\MysqlSearch;

use Magento\Framework\Search\Dynamic\DataProviderInterface;
use Magento\Framework\Search\Dynamic\EntityStorage;
use Magento\Framework\Search\Request\BucketInterface;

/**
 * Price range aggregation of the mysql_basic engine: not supported, so the price filter is not shown.
 */
class DynamicDataProvider implements DataProviderInterface
{
    public function __construct(private readonly Interval $interval)
    {
    }

    public function getRange()
    {
        return 0;
    }

    public function getAggregations(EntityStorage $entityStorage)
    {
        return ['count' => 0, 'max' => 0, 'min' => 0, 'std' => 0];
    }

    public function getInterval(BucketInterface $bucket, array $dimensions, EntityStorage $entityStorage)
    {
        return $this->interval;
    }

    public function getAggregation(BucketInterface $bucket, array $dimensions, $range, EntityStorage $entityStorage)
    {
        return [];
    }

    public function prepareData($range, array $dbRanges)
    {
        return [];
    }
}
