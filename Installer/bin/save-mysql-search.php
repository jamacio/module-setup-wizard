<?php
/**
 * Copyright © Jamacio. All rights reserved.
 *
 * Runs after setup:install when the wizard installs with the mysql_basic search engine:
 * setup:install only accepts its own engines, so the wizard put catalog/search/engine = mysql_basic
 * in app/etc/env.php for the installation. This script stores it in the database instead,
 * where it can be changed from the admin later, and removes it from env.php.
 */
declare(strict_types=1);

use Jamacio\SetupWizard\Installer\Database;
use Jamacio\SetupWizard\Installer\EnvFile;
use Jamacio\SetupWizard\Installer\Input;
use Jamacio\SetupWizard\Installer\Paths;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = require dirname(__DIR__) . '/bootstrap.php';
$envFile = new EnvFile((new Paths($root))->envFile());
$env = $envFile->read();
$connection = $env['db']['connection']['default'] ?? null;
if ($connection === null) {
    fwrite(STDERR, 'app/etc/env.php has no database connection.' . PHP_EOL);
    exit(1);
}

$table = Database::quoteIdentifier(($env['db']['table_prefix'] ?? '') . 'core_config_data');
Database::connect([
    'host' => (string) $connection['host'],
    'name' => (string) $connection['dbname'],
    'user' => (string) $connection['username'],
    'password' => (string) ($connection['password'] ?? ''),
    'prefix' => '',
])->prepare(
    "INSERT INTO {$table} (scope, scope_id, path, value) VALUES ('default', 0, 'catalog/search/engine', ?)
     ON DUPLICATE KEY UPDATE value = VALUES(value)"
)->execute([Input::SEARCH_ENGINE_MYSQL]);
echo 'core_config_data: catalog/search/engine = ' . Input::SEARCH_ENGINE_MYSQL . PHP_EOL;

unset($env['system']['default']['catalog']['search']['engine']);
// Drop the branches the wizard created if they are now empty.
if (empty($env['system']['default']['catalog']['search'])) {
    unset($env['system']['default']['catalog']['search']);
}
if (empty($env['system']['default']['catalog'])) {
    unset($env['system']['default']['catalog']);
}
if (empty($env['system']['default'])) {
    unset($env['system']['default']);
}
if (empty($env['system'])) {
    unset($env['system']);
}
$envFile->write($env, false);
echo 'app/etc/env.php: temporary search engine override removed' . PHP_EOL;
