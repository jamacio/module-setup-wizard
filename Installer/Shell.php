<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Installer;

final class Shell
{
    public static function canExec(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        foreach (['exec', 'shell_exec', 'proc_open'] as $function) {
            if (!function_exists($function) || in_array($function, $disabled, true)) {
                return false;
            }
        }

        return true;
    }

    public static function has(string $command): bool
    {
        return trim((string) @shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null')) !== '';
    }
}
