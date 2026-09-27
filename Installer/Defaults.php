<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Installer;

/**
 * Pre-filled form values, detected from the environment when possible
 * (Magento Cloud Docker exposes its services in MAGENTO_CLOUD_RELATIONSHIPS).
 */
final class Defaults
{
    /**
     * Store locale suggested for each wizard language.
     */
    private const STORE_LOCALES = [
        'pt_BR' => ['language' => 'pt_BR', 'currency' => 'BRL', 'timezone' => 'America/Sao_Paulo'],
        'es_ES' => ['language' => 'es_ES', 'currency' => 'EUR', 'timezone' => 'Europe/Madrid'],
    ];

    public static function detect(array $server, array $currentEnv, string $wizardLocale): array
    {
        $relationships = self::decodeCloudVariable('MAGENTO_CLOUD_RELATIONSHIPS');
        $variables = self::decodeCloudVariable('MAGENTO_CLOUD_VARIABLES');
        $database = $relationships['database'][0] ?? [];
        $currentDb = $currentEnv['db']['connection']['default'] ?? [];

        $searchHost = self::firstResolvable(
            ['opensearch', 'elasticsearch', $relationships['opensearch'][0]['host'] ?? null, $relationships['elasticsearch'][0]['host'] ?? null],
            'localhost'
        );
        $redisHost = self::firstResolvable(['redis', $relationships['redis'][0]['host'] ?? null], '');

        return [
            'db' => [
                'host' => $currentDb['host'] ?? self::firstResolvable([$database['host'] ?? null, 'db', 'mysql', 'mariadb'], 'localhost'),
                'name' => $currentDb['dbname'] ?? $database['path'] ?? 'magento2',
                'user' => $currentDb['username'] ?? $database['username'] ?? 'magento2',
                'password' => $currentDb['password'] ?? $database['password'] ?? '',
                'prefix' => $currentEnv['db']['table_prefix'] ?? '',
            ],
            'base_url' => self::requestBaseUrl($server),
            'backend_frontname' => $currentEnv['backend']['frontName'] ?? $variables['ADMIN_URL'] ?? 'admin',
            'admin' => [
                'firstname' => 'Admin',
                'lastname' => 'Magento',
                'email' => $variables['ADMIN_EMAIL'] ?? 'admin@example.com',
                'username' => 'admin',
            ],
            'locale' => self::STORE_LOCALES[$wizardLocale]
                ?? ['language' => 'en_US', 'currency' => 'USD', 'timezone' => 'America/Chicago'],
            'search' => [
                'engine' => $searchHost === 'elasticsearch' ? 'elasticsearch8' : 'opensearch',
                'host' => $searchHost,
                'port' => 9200,
            ],
            'redis' => [
                'enabled' => $redisHost !== '',
                'host' => $redisHost ?: 'localhost',
                'port' => (int) ($relationships['redis'][0]['port'] ?? 6379),
            ],
        ];
    }

    /**
     * Store URL as seen by the browser (Magento redirects here from the store root).
     */
    private static function requestBaseUrl(array $server): string
    {
        $https = ($server['HTTPS'] ?? 'off') !== 'off' && !empty($server['HTTPS'])
            || ($server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $host = $server['HTTP_HOST'] ?? 'localhost';

        return ($https ? 'https' : 'http') . '://' . $host . '/';
    }

    private static function decodeCloudVariable(string $name): array
    {
        $value = getenv($name);
        $decoded = $value ? json_decode((string) base64_decode($value, true), true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string|null> $hosts
     */
    private static function firstResolvable(array $hosts, string $fallback): string
    {
        foreach (array_unique(array_filter($hosts)) as $host) {
            if (filter_var($host, FILTER_VALIDATE_IP) || gethostbyname($host) !== $host) {
                return $host;
            }
        }

        return $fallback;
    }
}
