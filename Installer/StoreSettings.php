<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Installer;

use PDO;
use Throwable;

/**
 * Adapts core_config_data of an existing database (e.g. a production dump) to the
 * environment where it is being brought up.
 */
final class StoreSettings
{
    private const URL_OVERRIDES = [
        'web/unsecure/base_url', 'web/secure/base_url',
        'web/unsecure/base_link_url', 'web/secure/base_link_url',
        'web/unsecure/base_static_url', 'web/secure/base_static_url',
        'web/unsecure/base_media_url', 'web/secure/base_media_url',
        'web/secure/use_in_frontend', 'web/secure/use_in_adminhtml',
        'web/cookie/cookie_domain',
    ];

    /**
     * @param array $input Normalized wizard input, see Input::normalize().
     * @return string[] Human readable list of the changes, for the job log.
     */
    public static function apply(array $input): array
    {
        $pdo = Database::connect($input['db']);
        $table = Database::quoteIdentifier($input['db']['prefix'] . 'core_config_data');
        $baseUrl = $input['base_url'];
        $secure = str_starts_with($baseUrl, 'https://') ? '1' : '0';
        $changes = [];

        $pdo->beginTransaction();
        try {
            if ($input['options']['reset_urls']) {
                $placeholders = implode(',', array_fill(0, count(self::URL_OVERRIDES), '?'));
                $statement = $pdo->prepare("DELETE FROM {$table} WHERE path IN ({$placeholders})");
                $statement->execute(self::URL_OVERRIDES);
                $changes[] = (string) __(
                    'Removed %1 inherited URL/cookie settings (all scopes, including static/media CDN).',
                    $statement->rowCount()
                );
            }

            self::set($pdo, $table, [
                'web/unsecure/base_url' => $baseUrl,
                'web/secure/base_url' => $baseUrl,
                'web/unsecure/base_link_url' => '{{unsecure_base_url}}',
                'web/secure/base_link_url' => '{{secure_base_url}}',
                'web/secure/use_in_frontend' => $secure,
                'web/secure/use_in_adminhtml' => $secure,
            ]);
            $changes[] = (string) __('Base URL set to %1', $baseUrl);

            if ($input['search']['write']) {
                $engine = $input['search']['engine'];
                if ($engine === Input::SEARCH_ENGINE_MYSQL) {
                    self::set($pdo, $table, ['catalog/search/engine' => $engine]);
                    $changes[] = (string) __('Search engine: MySQL (basic, no search service).');
                } else {
                    $values = [
                        'catalog/search/engine' => $engine,
                        "catalog/search/{$engine}_server_hostname" => $input['search']['host'],
                        "catalog/search/{$engine}_server_port" => (string) $input['search']['port'],
                        "catalog/search/{$engine}_enable_auth" => '0',
                    ];
                    if ($input['search']['prefix'] !== '') {
                        $values["catalog/search/{$engine}_index_prefix"] = $input['search']['prefix'];
                    }
                    self::set($pdo, $table, $values);
                    $changes[] = (string) __(
                        'Search engine: %1 at %2:%3',
                        $engine,
                        $input['search']['host'],
                        $input['search']['port']
                    );
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $changes;
    }

    /**
     * Upserts values in the default scope (unique key: scope + scope_id + path).
     *
     * @param array<string, string> $values
     */
    private static function set(PDO $pdo, string $table, array $values): void
    {
        $statement = $pdo->prepare(
            "INSERT INTO {$table} (scope, scope_id, path, value) VALUES ('default', 0, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)"
        );
        foreach ($values as $path => $value) {
            $statement->execute([$path, $value]);
        }
    }
}
