<?php
/**
 * Copyright © Jamacio. All rights reserved.
 *
 * Runs before the final cache:flush of every run.
 *
 * Since Magento 2.4.9 the file caches use Symfony: entries live in var/cache and var/page_cache,
 * but the tag index that cache:flush relies on lives in var/cache/symfony. setup:install empties
 * var/cache, so full page cache entries of a previous install lose their index and survive every
 * cache:flush: the home page kept showing the empty store of the previous install after installing
 * sample data. Emptying both directories removes them; Magento rebuilds whatever it needs.
 * Redis caches are not touched (cache:flush handles them).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = require dirname(__DIR__) . '/bootstrap.php';

foreach (['var/cache', 'var/page_cache'] as $relative) {
    $dir = $root . '/' . $relative;
    if (!is_dir($dir)) {
        continue;
    }
    $removed = 0;
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($entries as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            @rmdir($entry->getPathname());
        } elseif (@unlink($entry->getPathname())) {
            $removed++;
        }
    }
    printf('%s: %d file(s) removed.%s', $relative, $removed, PHP_EOL);
}
