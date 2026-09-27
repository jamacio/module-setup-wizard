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
     * After a run finishes, its status JSON is still answered for this long, so the progress
     * page that was open (even in a throttled background tab) can show the final result.
     */
    private const FINAL_STATUS_SECONDS = 300;

    private static bool $armed = false;

    public static function arm(): void
    {
        if (self::$armed || PHP_SAPI === 'cli' || !\defined('BP') || !self::isFrontController()) {
            return;
        }
        $paths = new Paths(BP);
        if ((new EnvFile($paths->envFile()))->isInstalled() && !self::isActiveJobRequest($paths)) {
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
     * existing-database run), only the browser that started the run (owner cookie) is answered:
     * the progress page while the run is still going, and the status JSON until shortly after it
     * ends. Anyone else, and any request after that, gets Magento.
     */
    private static function isActiveJobRequest(Paths $paths): bool
    {
        $id = (string) ($_GET['job'] ?? '');
        $jobs = new JobManager($paths);
        if ($id === '' || !$jobs->isOwnedByRequest($id)) {
            return false;
        }
        $isStatusPoll = ($_GET['action'] ?? '') === 'status';

        return $jobs->isRecent($id, $isStatusPoll ? self::FINAL_STATUS_SECONDS : 0);
    }
}
