<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Model\MysqlSearch;

use Magento\Framework\Indexer\IndexStructureInterface;

/**
 * Index structure of the mysql_basic engine: nothing to create or drop.
 */
class IndexStructure implements IndexStructureInterface
{
    public function delete($index, array $dimensions = [])
    {
    }

    public function create($index, array $fields, array $dimensions = [])
    {
    }
}
