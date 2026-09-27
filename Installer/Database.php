<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Installer;

use PDO;
use PDOException;

/**
 * Direct PDO access to the store database. The Magento resource model is not
 * available while the application is not installed.
 */
final class Database
{
    private const ERROR_UNKNOWN_DATABASE = 1049;

    /**
     * @param array{host: string, name: string, user: string, password: string, prefix: string} $db
     */
    public static function connect(array $db, bool $selectDatabase = true): PDO
    {
        $host = trim($db['host']);
        if (str_starts_with($host, '/')) {
            $dsn = 'mysql:unix_socket=' . $host;
        } elseif (preg_match('/^(.+):(\d+)$/', $host, $matches)) {
            $dsn = 'mysql:host=' . $matches[1] . ';port=' . $matches[2];
        } else {
            $dsn = 'mysql:host=' . $host;
        }
        if ($selectDatabase) {
            $dsn .= ';dbname=' . $db['name'];
        }

        return new PDO($dsn . ';charset=utf8', $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    /**
     * Describes the target database without changing it.
     *
     * @return array{ok: bool, exists: bool, message: string, tables?: int, magento?: bool,
     *     server?: string, base_url?: ?string, stores?: int, products?: int}
     */
    public static function inspect(array $db): array
    {
        try {
            $pdo = self::connect($db);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === self::ERROR_UNKNOWN_DATABASE) {
                try {
                    self::connect($db, false);
                    return [
                        'ok' => true,
                        'exists' => false,
                        'message' => (string) __('Server OK, but database "%1" does not exist.', $db['name']),
                    ];
                } catch (PDOException) {
                    // Fall through to the original error.
                }
            }
            return ['ok' => false, 'exists' => false, 'message' => self::describe($e)];
        }

        $prefix = $db['prefix'];
        $tables = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->fetchColumn();
        $isMagento = self::tableExists($pdo, $prefix . 'core_config_data')
            && self::tableExists($pdo, $prefix . 'setup_module')
            && self::tableExists($pdo, $prefix . 'store');

        $result = [
            'ok' => true,
            'exists' => true,
            'tables' => $tables,
            'magento' => $isMagento,
            'server' => (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
        ];

        if ($isMagento) {
            $result['base_url'] = self::configValue($pdo, $prefix, 'web/unsecure/base_url');
            $result['stores'] = (int) $pdo->query(
                sprintf('SELECT COUNT(*) FROM %s WHERE store_id > 0', self::quoteIdentifier($prefix . 'store'))
            )->fetchColumn();
            $result['products'] = self::tableExists($pdo, $prefix . 'catalog_product_entity')
                ? (int) $pdo->query(
                    sprintf('SELECT COUNT(*) FROM %s', self::quoteIdentifier($prefix . 'catalog_product_entity'))
                )->fetchColumn()
                : 0;
            $result['message'] = (string) __(
                'Magento database found: %1 tables, %2 store(s), %3 product(s).',
                $tables,
                $result['stores'],
                $result['products']
            );
        } elseif ($tables === 0) {
            $result['message'] = (string) __('Connected. The database is empty.');
        } else {
            $result['message'] = (string) __(
                'Connected. The database has %1 tables but does not look like Magento (table prefix "%2").',
                $tables,
                $prefix
            );
        }

        return $result;
    }

    public static function createDatabase(array $db): void
    {
        self::connect($db, false)->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS %s CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
            self::quoteIdentifier($db['name'])
        ));
    }

    public static function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $statement->execute([$table]);

        return (bool) $statement->fetchColumn();
    }

    public static function configValue(PDO $pdo, string $prefix, string $path): ?string
    {
        $statement = $pdo->prepare(sprintf(
            "SELECT value FROM %s WHERE scope = 'default' AND scope_id = 0 AND path = ?",
            self::quoteIdentifier($prefix . 'core_config_data')
        ));
        $statement->execute([$path]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public static function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public static function describe(PDOException $e): string
    {
        return match ((int) ($e->errorInfo[1] ?? $e->getCode())) {
            1045 => (string) __('Access denied: wrong database user or password.'),
            2002, 2005 => (string) __('Could not reach the MySQL server (wrong host or port, or the server is down).'),
            default => $e->getMessage(),
        };
    }
}
