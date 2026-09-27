<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Installer;

/**
 * Environment readiness check, shown before the form.
 */
final class Requirements
{
    private const MIN_PHP = '8.1.0';

    private const EXTENSIONS = [
        'bcmath', 'ctype', 'curl', 'dom', 'fileinfo', 'filter', 'gd', 'hash', 'iconv', 'intl', 'json', 'libxml',
        'mbstring', 'openssl', 'pcre', 'pdo_mysql', 'simplexml', 'soap', 'sockets', 'sodium', 'spl', 'tokenizer',
        'xmlwriter', 'xsl', 'zip', 'zlib',
    ];

    private const WRITABLE_DIRS = ['app/etc', 'var', 'generated', 'pub/static', 'pub/media'];

    /**
     * @return list<array{label: string, ok: bool, detail: string, blocking: bool}>
     */
    public static function check(Paths $paths): array
    {
        $checks = [];
        $add = static function (string $label, bool $ok, string $detail, bool $blocking = true) use (&$checks): void {
            $checks[] = compact('label', 'ok', 'detail', 'blocking');
        };

        $canExec = Shell::canExec();
        $add(
            (string) __('Command execution'),
            $canExec,
            $canExec ? (string) __('exec/proc_open available.') : (string) __('exec, shell_exec or proc_open are listed in disable_functions.')
        );

        $cli = PhpCli::info();
        $add('PHP CLI', $cli !== null, $cli ? $cli['binary'] : (string) __('PHP CLI binary not found (set PHP_CLI_BINARY).'));

        if ($cli !== null) {
            $versionOk = version_compare($cli['version'], self::MIN_PHP, '>=');
            $add(
                (string) __('PHP version'),
                $versionOk,
                $versionOk ? $cli['version'] : (string) __('%1 (minimum %2)', $cli['version'], self::MIN_PHP)
            );

            $missing = array_values(array_diff(self::EXTENSIONS, $cli['extensions']));
            $add(
                (string) __('PHP extensions'),
                $missing === [],
                $missing === []
                    ? (string) __('All %1 required extensions are loaded.', count(self::EXTENSIONS))
                    : (string) __('Missing: %1', implode(', ', $missing))
            );
        }

        $add(
            (string) __('Magento codebase'),
            is_file($paths->magentoBin()) && is_file($paths->path('vendor/autoload.php')),
            is_file($paths->path('vendor/autoload.php'))
                ? (string) __('bin/magento and vendor/ are present.')
                : (string) __('Run "composer install" first.')
        );

        $notWritable = array_values(array_filter(self::WRITABLE_DIRS, static function (string $dir) use ($paths): bool {
            $path = $paths->path($dir);
            return is_dir($path) ? !is_writable($path) : !is_writable(dirname($path));
        }));
        $add(
            (string) __('Write permissions'),
            $notWritable === [],
            $notWritable === [] ? implode(', ', self::WRITABLE_DIRS) : (string) __('Not writable: %1', implode(', ', $notWritable))
        );

        $add(
            'app/etc/config.php',
            is_file($paths->configFile()),
            is_file($paths->configFile())
                ? (string) __('Module list is present.')
                : (string) __('Missing: a fresh install generates it; with an existing database, setup:upgrade enables every module.'),
            false
        );

        return $checks;
    }

    public static function passed(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['blocking'] && !$check['ok']) {
                return false;
            }
        }

        return true;
    }
}
