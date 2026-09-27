<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Installer;

/**
 * Normalizes and validates the wizard form.
 */
final class Input
{
    public const MODE_NEW = 'new';
    public const MODE_EXISTING = 'existing';

    /**
     * Basic SQL search engine shipped by this module (Model/MysqlSearch), for running without a search service.
     */
    public const SEARCH_ENGINE_MYSQL = 'mysql_basic';

    public const SEARCH_ENGINES = [
        'opensearch' => 'OpenSearch',
        'elasticsearch8' => 'Elasticsearch 8',
        self::SEARCH_ENGINE_MYSQL => 'MySQL (basic, no search service)',
    ];

    public static function normalize(array $post): array
    {
        $text = static fn (array $source, string $key, string $default = ''): string
            => trim((string) ($source[$key] ?? $default));
        $flag = static fn (array $source, string $key): bool
            => in_array($source[$key] ?? '', ['1', 'on', 'true'], true);

        $db = (array) ($post['db'] ?? []);
        $admin = (array) ($post['admin'] ?? []);
        $locale = (array) ($post['locale'] ?? []);
        $search = (array) ($post['search'] ?? []);
        $redis = (array) ($post['redis'] ?? []);
        $options = (array) ($post['options'] ?? []);

        $baseUrl = $text($post, 'base_url');
        if ($baseUrl !== '' && !str_ends_with($baseUrl, '/')) {
            $baseUrl .= '/';
        }

        $mode = ($post['mode'] ?? '') === self::MODE_EXISTING ? self::MODE_EXISTING : self::MODE_NEW;

        return [
            'mode' => $mode,
            'db' => [
                'host' => $text($db, 'host'),
                'name' => $text($db, 'name'),
                'user' => $text($db, 'user'),
                // Passwords are taken verbatim: leading/trailing spaces are valid characters.
                'password' => (string) ($db['password'] ?? ''),
                'prefix' => $text($db, 'prefix'),
            ],
            'base_url' => $baseUrl,
            'backend_frontname' => $text($post, 'backend_frontname', 'admin'),
            'admin' => [
                // A fresh install always creates the admin; with an existing database it is optional.
                'enabled' => $mode === self::MODE_NEW || $flag($admin, 'enabled'),
                'firstname' => $text($admin, 'firstname'),
                'lastname' => $text($admin, 'lastname'),
                'email' => $text($admin, 'email'),
                'username' => $text($admin, 'username'),
                'password' => (string) ($admin['password'] ?? ''),
            ],
            'locale' => [
                'language' => $text($locale, 'language'),
                'currency' => strtoupper($text($locale, 'currency')),
                'timezone' => $text($locale, 'timezone'),
            ],
            'search' => [
                'engine' => $text($search, 'engine', 'opensearch'),
                'host' => $text($search, 'host'),
                'port' => (int) $text($search, 'port', '9200'),
                'prefix' => $text($search, 'prefix'),
                'write' => $mode === self::MODE_NEW || $flag($search, 'write'),
            ],
            'redis' => [
                'enabled' => $flag($redis, 'enabled'),
                'host' => $text($redis, 'host'),
                'port' => (int) $text($redis, 'port', '6379'),
            ],
            'crypt_key' => $text($post, 'crypt_key'),
            'options' => [
                'cleanup_db' => $flag($options, 'cleanup_db'),
                'sample_data' => $flag($options, 'sample_data'),
                'use_rewrites' => $flag($options, 'use_rewrites'),
                'disable_2fa' => $flag($options, 'disable_2fa'),
                'reset_urls' => $flag($options, 'reset_urls'),
                'setup_upgrade' => $flag($options, 'setup_upgrade'),
                'reindex' => $flag($options, 'reindex'),
            ],
        ];
    }

    /**
     * @return array<string, string> Error messages keyed by field ("db.host", "admin.password", ...).
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $isNew = $in['mode'] === self::MODE_NEW;

        foreach (['host' => 'Enter the database host.', 'name' => 'Enter the database name.', 'user' => 'Enter the database user.'] as $key => $message) {
            if ($in['db'][$key] === '') {
                $errors['db.' . $key] = (string) __($message);
            }
        }
        if (!preg_match('/^[a-zA-Z0-9_]{0,5}$/', $in['db']['prefix'])) {
            $errors['db.prefix'] = (string) __('Up to 5 characters: letters, numbers and "_".');
        }

        $scheme = parse_url($in['base_url'], PHP_URL_SCHEME);
        if (!filter_var($in['base_url'], FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
            $errors['base_url'] = (string) __('Enter a full URL, e.g. http://localhost/');
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $in['backend_frontname'])) {
            $errors['backend_frontname'] = (string) __('Use only letters, numbers and "_".');
        }

        if ($in['admin']['enabled']) {
            foreach (['firstname' => 'Enter the first name.', 'lastname' => 'Enter the last name.', 'username' => 'Enter the username.'] as $key => $message) {
                if ($in['admin'][$key] === '') {
                    $errors['admin.' . $key] = (string) __($message);
                }
            }
            if (!filter_var($in['admin']['email'], FILTER_VALIDATE_EMAIL)) {
                $errors['admin.email'] = (string) __('Enter a valid email address.');
            }
            // Magento rule: at least 7 characters, with both letters and numbers.
            $password = $in['admin']['password'];
            if (strlen($password) < 7 || !preg_match('/[a-zA-Z]/', $password) || !preg_match('/\d/', $password)) {
                $errors['admin.password'] = (string) __('At least 7 characters, with letters and numbers.');
            }
        }

        if ($isNew) {
            // Same lists setup:install validates against.
            if (!StoreLocaleOptions::isLanguage($in['locale']['language'])) {
                $errors['locale.language'] = (string) __('Choose a language from the list.');
            }
            if (!StoreLocaleOptions::isCurrency($in['locale']['currency'])) {
                $errors['locale.currency'] = (string) __('Choose a currency from the list.');
            }
            if (!StoreLocaleOptions::isTimezone($in['locale']['timezone'])) {
                $errors['locale.timezone'] = (string) __('Choose a time zone from the list.');
            }
        }

        if ($in['search']['write']) {
            if (!isset(self::SEARCH_ENGINES[$in['search']['engine']])) {
                $errors['search.engine'] = (string) __('Unsupported search engine.');
            }
            if ($in['search']['engine'] !== self::SEARCH_ENGINE_MYSQL) {
                if ($in['search']['host'] === '') {
                    $errors['search.host'] = (string) __('Enter the search engine host.');
                }
                if ($in['search']['port'] < 1 || $in['search']['port'] > 65535) {
                    $errors['search.port'] = (string) __('Invalid port.');
                }
                if (!preg_match('/^[a-z0-9_-]*$/', $in['search']['prefix'])) {
                    $errors['search.prefix'] = (string) __('Use lowercase letters, numbers, "_" or "-".');
                }
            }
        }

        if ($in['redis']['enabled']) {
            if ($in['redis']['host'] === '') {
                $errors['redis.host'] = (string) __('Enter the Redis host.');
            }
            if ($in['redis']['port'] < 1 || $in['redis']['port'] > 65535) {
                $errors['redis.port'] = (string) __('Invalid port.');
            }
        }

        return $errors;
    }
}
