<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Installer;

/**
 * Zero-configuration entry point: hooks the wizard into Magento's own front controller.
 *
 * The module's registration.php runs on every request while the Composer autoloader starts,
 * before Magento boots. While Magento is not installed, every web request reaches index.php
 * (Nginx try_files, Apache .htaccess), so the wizard answers there. No web server rule and no
 * /setup/ route are needed.
 *
 * The wizard only takes over once the autoloader is complete: it waits for the first use of
 * Magento\Framework\App\Bootstrap, which app/bootstrap.php makes right after autoloading.
 */
final class Intercept
{
    private const BOOTSTRAP_CLASS = 'Magento\\Framework\\App\\Bootstrap';

    /**
     * After installation, the progress page of a run keeps working for this long.
     */
    private const RECENT_JOB_SECONDS = 1800;

    private static bool $armed = false;

    public static function arm(): void
    {
        if (self::$armed || PHP_SAPI === 'cli' || !\defined('BP') || !self::isFrontController()) {
            return;
        }
        $paths = new Paths(BP);
        if ((new EnvFile($paths->envFile()))->isInstalled() && !self::isRecentJobRequest($paths)) {
            return;
        }
        self::$armed = true;

        if (class_exists(self::BOOTSTRAP_CLASS, false)) {
            self::run($paths);
        }
        spl_autoload_register(static function (string $class) use ($paths): void {
            if ($class === self::BOOTSTRAP_CLASS) {
                self::run($paths);
            }
        }, true, true);
    }

    private static function run(Paths $paths): never
    {
        (new App($paths))->run();
        exit;
    }

    /**
     * Only Magento's index.php (pub/ or project root document root); static.php, get.php,
     * health_check.php and the error pages are left alone.
     */
    private static function isFrontController(): bool
    {
        $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));

        return $script !== false
            && \in_array($script, array_filter([realpath(BP . '/pub/index.php'), realpath(BP . '/index.php')]), true);
    }

    /**
     * Once env.php has an install date (at the end of setup:install, or at the start of an
     * existing-database run), only the page and status requests of a recent run are answered,
     * and only for the browser that started it (owner cookie). Anyone else gets Magento.
     */
    private static function isRecentJobRequest(Paths $paths): bool
    {
        $id = (string) ($_GET['job'] ?? '');
        $jobs = new JobManager($paths);

        return $id !== '' && $jobs->isOwnedByRequest($id) && $jobs->isRecent($id, self::RECENT_JOB_SECONDS);
    }
}
