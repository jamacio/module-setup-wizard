<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Model\MysqlSearch;

use Magento\Framework\Indexer\SaveHandler\IndexerInterface;

/**
 * Fulltext index handler of the mysql_basic engine: nothing to write, the Adapter reads the catalog tables directly.
 */
class IndexerHandler implements IndexerInterface
{
    public function saveIndex($dimensions, \Traversable $documents)
    {
        return $this;
    }

    public function deleteIndex($dimensions, \Traversable $documents)
    {
        return $this;
    }

    public function cleanIndex($dimensions)
    {
        return $this;
    }

    public function isAvailable($dimensions = [])
    {
        return true;
    }
}
