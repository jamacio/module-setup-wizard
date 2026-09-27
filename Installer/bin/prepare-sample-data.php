<?php
/**
 * Copyright © Jamacio. All rights reserved.
 *
 * Runs before setup:install when the wizard installs sample data.
 *
 * The sample data media (magento/sample-data-media) is copied to pub/media by Composer only
 * once. Installing the downloadable sample products then MOVES their files (for example
 * pub/media/downloadable/files/links/... to pub/media/downloadable/downloadable/files/links/...),
 * so every later install fails with "Sample Data error: file_get_contents(...): Failed to open
 * stream". This script copies back the files missing from pub/media (it never overwrites) and
 * clears var/.sample-data-state.flag, so "Sample Data is installed with errors" only reports
 * errors of the current install.
 */
declare(strict_types=1);

use Composer\InstalledVersions;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = require dirname(__DIR__) . '/bootstrap.php';

$source = null;
if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('magento/sample-data-media')) {
    $source = realpath((string) InstalledVersions::getInstallPath('magento/sample-data-media')) ?: null;
}
$source ??= is_dir($root . '/vendor/magento/sample-data-media') ? $root . '/vendor/magento/sample-data-media' : null;

if ($source === null) {
    echo 'magento/sample-data-media is not installed; product images of the sample data will be missing.' . PHP_EOL;
} else {
    $target = $root . '/pub/media';
    $copied = 0;
    $present = 0;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $relative = substr($file->getPathname(), strlen($source) + 1);
        // Package metadata, not media (Composer's "map" copies them too, harmlessly).
        if (in_array($relative, ['composer.json', 'LICENSE.txt', 'LICENSE_AFL.txt', 'README.md'], true)) {
            continue;
        }
        $destination = $target . '/' . $relative;
        if (is_file($destination)) {
            $present++;
            continue;
        }
        if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0775, true) && !is_dir(dirname($destination))) {
            fwrite(STDERR, 'Could not create ' . dirname($destination) . PHP_EOL);
            exit(1);
        }
        if (!copy($file->getPathname(), $destination)) {
            fwrite(STDERR, 'Could not copy ' . $relative . PHP_EOL);
            exit(1);
        }
        $copied++;
    }
    printf('Sample data media: %d file(s) restored to pub/media, %d already present.%s', $copied, $present, PHP_EOL);
}

$flag = $root . '/var/.sample-data-state.flag';
if (is_file($flag)) {
    unlink($flag);
    echo 'var/.sample-data-state.flag cleared (state of a previous install).' . PHP_EOL;
}
