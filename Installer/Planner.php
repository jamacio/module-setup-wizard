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
     * Magento_SampleData ships with every Magento: it is only the framework that installs the
     * data of the other modules, so on its own there is no sample data to install.
     */
    private const SAMPLE_DATA_FRAMEWORK = 'Magento_SampleData';

    /**
     * Composer repository of the Magento packages, including the sample data.
     */
    public const REPO_HOST = 'repo.magento.com';

    /**
     * Composer files that "bin/magento sampledata:deploy" (composer require) changes.
     */
    public const SAMPLE_DATA_DEPLOY_WRITABLE = ['composer.json', 'composer.lock', 'vendor'];

    /**
     * Sample data modules in the codebase (Magento_*SampleData, added by "bin/magento sampledata:deploy").
     * setup:install installs their data whenever they are enabled; --use-sample-data does not change that.
     *
     * @return list<string>
     */
    public static function sampleDataModules(): array
    {
        $modules = array_keys((new ComponentRegistrar())->getPaths(ComponentRegistrar::MODULE));

        return array_values(array_filter(
            $modules,
            static fn (string $module): bool => $module !== self::SAMPLE_DATA_FRAMEWORK && str_ends_with($module, 'SampleData')
        ));
    }

    /**
     * Sample data was asked for but is not in the codebase yet: the job downloads it first.
     */
    public static function needsSampleDataDeploy(array $in): bool
    {
        return $in['mode'] === Input::MODE_NEW && $in['options']['sample_data'] && self::sampleDataModules() === [];
    }

    /**
     * Whether an auth.json Composer reads during sampledata:deploy already has repo.magento.com keys:
     * the project's own, or the one in Magento's Composer home (var/composer_home).
     */
    public static function hasRepoCredentials(Paths $paths): bool
    {
        foreach (['auth.json', 'var/composer_home/auth.json'] as $file) {
            $path = $paths->path($file);
            $auth = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            if (is_array($auth) && !empty($auth['http-basic'][self::REPO_HOST]['username'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{label: string, argv: list<string>, env?: array<string, string>}>
     */
    public function plan(array $in): array
    {
        $steps = [];
        if ($in['mode'] === Input::MODE_NEW) {
            if (self::needsSampleDataDeploy($in)) {
                $step = [
                    'label' => (string) __('Downloading the sample data (sampledata:deploy)'),
                    'argv' => $this->magento(['sampledata:deploy', '--no-interaction']),
                ];
                if ($in['repo']['public_key'] !== '') {
                    // Only in the environment of this step: the keys are never written to auth.json.
                    $step['env'] = ['COMPOSER_AUTH' => json_encode([
                        'http-basic' => [self::REPO_HOST => [
                            'username' => $in['repo']['public_key'],
                            'password' => $in['repo']['private_key'],
                        ]],
                    ])];
                }
                $steps[] = $step;
            }
            if ($in['options']['sample_data']) {
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

        $steps[] = ['label' => (string) __('Clearing the cache files'), 'argv' => [$this->php, $this->paths->fileCacheCleaner()]];
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
        return array_values(array_filter(
            [$in['db']['password'], $in['admin']['password'], $in['crypt_key'], $in['repo']['public_key'], $in['repo']['private_key']],
            'strlen'
        ));
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
        // After sampledata:deploy the modules are new to app/etc/config.php, and setup:install enables new modules.
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
