<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Installer;

/**
 * Locates the PHP CLI binary. Under PHP-FPM, PHP_BINARY points to php-fpm,
 * which cannot run bin/magento.
 */
final class PhpCli
{
    private static bool $resolved = false;

    /** @var array{binary: string, version: string, extensions: string[]}|null */
    private static ?array $info = null;

    /**
     * @return array{binary: string, version: string, extensions: string[]}|null
     */
    public static function info(): ?array
    {
        if (self::$resolved) {
            return self::$info;
        }
        self::$resolved = true;

        if (!Shell::canExec()) {
            return null;
        }

        $candidates = array_filter([
            getenv('PHP_CLI_BINARY') ?: null,
            PHP_BINDIR . '/php',
            '/usr/local/bin/php',
            '/usr/bin/php',
            trim((string) @shell_exec('command -v php 2>/dev/null')),
        ]);

        $probe = 'echo PHP_SAPI, "|", PHP_VERSION, "|", implode(",", get_loaded_extensions());';
        foreach (array_unique($candidates) as $binary) {
            if (!@is_executable($binary)) {
                continue;
            }
            $output = [];
            @exec(escapeshellarg($binary) . ' -r ' . escapeshellarg($probe) . ' 2>/dev/null', $output, $code);
            $parts = explode('|', (string) ($output[0] ?? ''), 3);
            if ($code === 0 && count($parts) === 3 && $parts[0] === 'cli') {
                return self::$info = [
                    'binary' => $binary,
                    'version' => $parts[1],
                    'extensions' => array_map('strtolower', explode(',', $parts[2])),
                ];
            }
        }

        return null;
    }
}
