<?php
/**
 * Copyright © Jamacio. All rights reserved.
 *
 * Bootstrap for the wizard's CLI scripts (bin/run-job.php, bin/save-mysql-search.php, ...).
 * It must work while Magento is NOT installed, so it only loads the Composer
 * autoloader (no object manager, no deployment config). The web side needs no bootstrap:
 * it runs inside Magento's own index.php, see Intercept.php.
 */
declare(strict_types=1);

return (static function (): string {
    $dir = __DIR__;
    while (!is_file($dir . '/bin/magento') || !is_file($dir . '/app/etc/di.xml')) {
        $parent = dirname($dir);
        if ($parent === $dir) {
            throw new RuntimeException('Magento root directory could not be detected from ' . __DIR__);
        }
        $dir = $parent;
    }

    require_once $dir . '/app/autoload.php';

    // Installed through Composer the module lives in vendor/ and is autoloaded by its own
    // composer.json; inside app/code it is resolved by the project's PSR-0 fallback.
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Jamacio\\SetupWizard\\Installer\\';
        if (str_starts_with($class, $prefix)) {
            $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    });

    return $dir;
})();
