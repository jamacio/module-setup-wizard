<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Installer;

/**
 * Filesystem locations used by the wizard, all relative to the Magento root.
 */
final class Paths
{
    public function __construct(private readonly string $root)
    {
    }

    public function root(): string
    {
        return $this->root;
    }

    public function path(string $relative): string
    {
        return $this->root . '/' . ltrim($relative, '/');
    }

    public function envFile(): string
    {
        return $this->path('app/etc/env.php');
    }

    public function configFile(): string
    {
        return $this->path('app/etc/config.php');
    }

    public function magentoBin(): string
    {
        return $this->path('bin/magento');
    }

    /**
     * Job files (commands, status, logs). var/ is outside the web root.
     */
    public function workDir(): string
    {
        return $this->path('var/jamacio_setup_wizard');
    }

    public function runner(): string
    {
        return __DIR__ . '/bin/run-job.php';
    }

    /**
     * Moves the mysql_basic search engine from env.php to the database after setup:install.
     */
    public function mysqlSearchFinalizer(): string
    {
        return __DIR__ . '/bin/save-mysql-search.php';
    }

    /**
     * Restores the sample data media consumed by a previous install (see the script header).
     */
    public function sampleDataPreparer(): string
    {
        return __DIR__ . '/bin/prepare-sample-data.php';
    }

    public function view(string $name): string
    {
        return __DIR__ . '/view/' . $name;
    }
}
