<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Installer;

use Magento\Framework\Component\ComponentRegistrar;

/**
 * Turns the validated form into the list of bin/magento commands the job will run.
 */
final class Planner
{
    private const TWO_FACTOR_MODULES = ['Magento_AdminAdobeImsTwoFactorAuth', 'Magento_TwoFactorAuth'];

    public function __construct(
        private readonly Paths $paths,
        private readonly string $php
    ) {
    }

    /**
     * Sample data modules in the codebase (Magento_*SampleData, added by "bin/magento sampledata:deploy").
     * setup:install installs their data whenever they are enabled; --use-sample-data does not change that.
     *
     * @return list<string>
     */
    public static function sampleDataModules(): array
    {
        $modules = array_keys((new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE));

        return array_values(array_filter($modules, static fn (string $module): bool => str_ends_with($module, 'SampleData')));
    }

    /**
     * @return list<array{label: string, argv: list<string>}>
     */
    public function plan(array $in): array
    {
        $steps = [];
        if ($in['mode'] === Input::MODE_NEW) {
            if ($in['options']['sample_data'] && self::sampleDataModules() !== []) {
                $steps[] = [
                    'label' => (string) __('Preparing the sample data media'),
                    'argv' => [$this->php, $this->paths->sampleDataPreparer()],
                ];
            }
            $steps[] = ['label' => (string) __('Installing Magento (setup:install)'), 'argv' => $this->magento($this->installArguments($in))];
            if ($in['search']['engine'] === Input::SEARCH_ENGINE_MYSQL) {
                // setup:install only accepts its own engines; App::start() put mysql_basic in env.php for the install.
                $steps[] = [
                    'label' => (string) __('Saving the MySQL search engine'),
                    'argv' => [$this->php, $this->paths->mysqlSearchFinalizer()],
                ];
            }
        }

        if ($in['options']['disable_2fa']) {
            $modules = $this->existingModules(self::TWO_FACTOR_MODULES);
            if ($modules !== []) {
                $steps[] = [
                    'label' => (string) __('Disabling two-factor authentication'),
                    'argv' => $this->magento(['module:disable', ...$modules, '--clear-static-content']),
                ];
            }
        }

        if ($in['mode'] === Input::MODE_EXISTING) {
            if ($in['options']['setup_upgrade']) {
                $steps[] = ['label' => (string) __('Upgrading schema and data (setup:upgrade)'), 'argv' => $this->magento(['setup:upgrade'])];
            }
            if ($in['admin']['enabled']) {
                $steps[] = [
                    'label' => (string) __('Creating or updating the admin user'),
                    'argv' => $this->magento([
                        'admin:user:create',
                        '--admin-user=' . $in['admin']['username'],
                        '--admin-password=' . $in['admin']['password'],
                        '--admin-email=' . $in['admin']['email'],
                        '--admin-firstname=' . $in['admin']['firstname'],
                        '--admin-lastname=' . $in['admin']['lastname'],
                    ]),
                ];
            }
            if ($in['options']['reindex']) {
                $steps[] = ['label' => (string) __('Reindexing (indexer:reindex)'), 'argv' => $this->magento(['indexer:reindex'])];
            }
        }

        $steps[] = ['label' => (string) __('Flushing the cache'), 'argv' => $this->magento(['cache:flush'])];

        return $steps;
    }

    /**
     * Values that must never be written to the log.
     *
     * @return list<string>
     */
    public static function secrets(array $in): array
    {
        return array_values(array_filter([$in['db']['password'], $in['admin']['password'], $in['crypt_key']], 'strlen'));
    }

    private function installArguments(array $in): array
    {
        $db = $in['db'];
        $admin = $in['admin'];
        $search = $in['search'];
        $secure = str_starts_with($in['base_url'], 'https://');
        // setup:install flags use "elasticsearch-*" for Elasticsearch 8 and "opensearch-*" for OpenSearch.
        $searchFlag = $search['engine'] === 'opensearch' ? 'opensearch' : 'elasticsearch';

        $args = [
            'setup:install',
            '--no-interaction',
            '--base-url=' . $in['base_url'],
            '--db-host=' . $db['host'],
            '--db-name=' . $db['name'],
            '--db-user=' . $db['user'],
            '--db-password=' . $db['password'],
            '--db-prefix=' . $db['prefix'],
            '--backend-frontname=' . $in['backend_frontname'],
            '--admin-firstname=' . $admin['firstname'],
            '--admin-lastname=' . $admin['lastname'],
            '--admin-email=' . $admin['email'],
            '--admin-user=' . $admin['username'],
            '--admin-password=' . $admin['password'],
            '--language=' . $in['locale']['language'],
            '--currency=' . $in['locale']['currency'],
            '--timezone=' . $in['locale']['timezone'],
            '--use-rewrites=' . ($in['options']['use_rewrites'] ? '1' : '0'),
        ];

        if ($search['engine'] !== Input::SEARCH_ENGINE_MYSQL) {
            array_push(
                $args,
                '--search-engine=' . $search['engine'],
                "--{$searchFlag}-host=" . $search['host'],
                "--{$searchFlag}-port=" . $search['port']
            );
            if ($search['prefix'] !== '') {
                $args[] = "--{$searchFlag}-index-prefix=" . $search['prefix'];
            }
        }
        if ($secure) {
            array_push($args, '--base-url-secure=' . $in['base_url'], '--use-secure=1', '--use-secure-admin=1');
        }
        if ($in['crypt_key'] !== '') {
            $args[] = '--key=' . $in['crypt_key'];
        }
        if ($in['options']['cleanup_db']) {
            $args[] = '--cleanup-database';
        }
        if (self::sampleDataModules() !== []) {
            // Always explicit: app/etc/config.php survives reinstalls (even with --cleanup-database)
            // and setup:install keeps the module status found there, so a previous install without
            // sample data would otherwise keep them disabled.
            $args[] = ($in['options']['sample_data'] ? '--enable-modules=' : '--disable-modules=')
                . implode(',', self::sampleDataModules());
        }
        if ($in['redis']['enabled']) {
            $host = $in['redis']['host'];
            $port = (string) $in['redis']['port'];
            array_push(
                $args,
                '--session-save=redis',
                '--session-save-redis-host=' . $host,
                '--session-save-redis-port=' . $port,
                '--session-save-redis-db=2',
                '--cache-backend=redis',
                '--cache-backend-redis-server=' . $host,
                '--cache-backend-redis-port=' . $port,
                '--cache-backend-redis-db=0',
                '--page-cache=redis',
                '--page-cache-redis-server=' . $host,
                '--page-cache-redis-port=' . $port,
                '--page-cache-redis-db=1'
            );
        }

        return $args;
    }

    /**
     * module:disable fails on unknown modules, so only pass the ones this codebase ships.
     * Registration files are loaded by the Composer autoloader, so this works before install.
     */
    private function existingModules(array $modules): array
    {
        $registered = (new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE);

        return array_values(array_filter($modules, static fn (string $module): bool => isset($registered[$module])));
    }

    private function magento(array $args): array
    {
        return [$this->php, '-d', 'memory_limit=-1', $this->paths->magentoBin(), ...$args];
    }
}
