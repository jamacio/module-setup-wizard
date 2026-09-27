<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Installer;

use Magento\Framework\App\DeploymentConfig\Writer\PhpFormatter;
use Magento\Framework\Config\ConfigOptionsListConstants;
use RuntimeException;

/**
 * Reads and writes app/etc/env.php.
 */
final class EnvFile
{
    private const CACHE_TYPES = [
        'config', 'layout', 'block_html', 'collections', 'reflection', 'db_ddl', 'compiled_config', 'eav',
        'customer_notification', 'config_integration', 'config_integration_api', 'graphql_query_resolver_result',
        'full_page', 'config_webservice', 'translate',
    ];

    public function __construct(private readonly string $file)
    {
    }

    public function read(): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $this->invalidateOpcache();
        $data = include $this->file;

        return is_array($data) ? $data : [];
    }

    /**
     * Same rule as Magento\Framework\App\DeploymentConfig::isAvailable().
     */
    public function isInstalled(): bool
    {
        return !empty($this->read()['install']['date']);
    }

    /**
     * Writes the file atomically, keeping a timestamped backup of the previous one unless $backup is false.
     *
     * @return string|null Path of the backup, when one was made.
     */
    public function write(array $data, bool $backup = true): ?string
    {
        $backupFile = null;
        if ($backup && is_file($this->file)) {
            $backupFile = $this->file . '.bak-' . date('YmdHis');
            if (!copy($this->file, $backupFile)) {
                throw new RuntimeException((string) __('Could not back up %1', $this->file));
            }
        }

        $content = class_exists(PhpFormatter::class)
            ? (new PhpFormatter())->format($data)
            : "<?php\nreturn " . var_export($data, true) . ";\n";

        $tmp = $this->file . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $content, LOCK_EX) === false || !rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new RuntimeException((string) __('Could not write %1', $this->file));
        }
        $this->invalidateOpcache();

        return $backupFile;
    }

    /**
     * Deployment configuration for a store whose database already exists (the part of
     * setup:install that does not touch the database).
     *
     * @param array $input Normalized wizard input, see Input::normalize().
     * @param array $current Current env.php content; top-level keys not generated here are kept.
     */
    public static function buildForExistingDatabase(array $input, string $root, array $current): array
    {
        $cachePrefix = substr(hash('sha256', $root), 0, 3) . '_';
        $redis = $input['redis'];

        $config = [
            'backend' => ['frontName' => $input['backend_frontname']],
            'remote_storage' => ['driver' => 'file'],
            'queue' => ['consumers_wait_for_messages' => 1],
            'crypt' => ['key' => $input['crypt_key'] ?: ($current['crypt']['key'] ?? self::generateKey())],
            'db' => [
                'table_prefix' => $input['db']['prefix'],
                'connection' => [
                    'default' => [
                        'host' => $input['db']['host'],
                        'dbname' => $input['db']['name'],
                        'username' => $input['db']['user'],
                        'password' => $input['db']['password'],
                        'model' => 'mysql4',
                        'engine' => 'innodb',
                        'initStatements' => 'SET NAMES utf8;',
                        'active' => '1',
                        'driver_options' => [1014 => false],
                    ],
                ],
            ],
            'resource' => ['default_setup' => ['connection' => 'default']],
            'x-frame-options' => 'SAMEORIGIN',
            'MAGE_MODE' => $current['MAGE_MODE'] ?? 'default',
            'session' => $redis['enabled'] ? self::redisSession($redis) : ['save' => 'files'],
            'cache' => [
                'frontend' => [
                    'default' => ['id_prefix' => $cachePrefix] + ($redis['enabled'] ? self::redisCache($redis, 0) : []),
                    'page_cache' => ['id_prefix' => $cachePrefix] + ($redis['enabled'] ? self::redisCache($redis, 1) : []),
                ],
                'allow_parallel_generation' => false,
            ],
            'lock' => ['provider' => 'db'],
            'directories' => ['document_root_is_pub' => true],
            'cache_types' => array_replace(array_fill_keys(self::CACHE_TYPES, 1), $current['cache_types'] ?? []),
            'downloadable_domains' => [(string) parse_url($input['base_url'], PHP_URL_HOST)],
            'install' => ['date' => date('D, d M Y H:i:s O')],
        ];

        return array_replace($current, $config);
    }

    private static function redisSession(array $redis): array
    {
        return [
            'save' => 'redis',
            'redis' => [
                'host' => $redis['host'],
                'port' => (string) $redis['port'],
                'password' => '',
                'timeout' => '2.5',
                'persistent_identifier' => '',
                'database' => '2',
                'compression_threshold' => '2048',
                'compression_library' => 'gzip',
                'log_level' => '1',
                'max_concurrency' => '6',
                'break_after_frontend' => '5',
                'break_after_adminhtml' => '30',
                'first_lifetime' => '600',
                'bot_first_lifetime' => '60',
                'bot_lifetime' => '7200',
                'disable_locking' => '0',
                'min_lifetime' => '60',
                'max_lifetime' => '2592000',
            ],
        ];
    }

    private static function redisCache(array $redis, int $database): array
    {
        return [
            'backend' => 'Magento\\Framework\\Cache\\Backend\\Redis',
            'backend_options' => [
                'server' => $redis['host'],
                'database' => (string) $database,
                'port' => (string) $redis['port'],
                'password' => '',
                'compress_data' => '1',
                'compression_lib' => '',
            ],
        ];
    }

    /**
     * Same format as Magento\Setup\Model\CryptKeyGenerator.
     */
    private static function generateKey(): string
    {
        if (defined(ConfigOptionsListConstants::class . '::STORE_KEY_ENCODED_RANDOM_STRING_PREFIX')) {
            return ConfigOptionsListConstants::STORE_KEY_ENCODED_RANDOM_STRING_PREFIX
                . base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_KEYBYTES));
        }

        return md5(random_bytes(32));
    }

    private function invalidateOpcache(): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->file, true);
        }
    }
}
