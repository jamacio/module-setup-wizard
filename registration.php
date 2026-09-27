<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(ComponentRegistrar::MODULE, 'Jamacio_SetupWizard', __DIR__);

// While Magento is not installed, web requests to index.php are answered by the Setup Wizard
// (no web server configuration needed). See Installer/Intercept.php.
if (PHP_SAPI !== 'cli') {
    \Jamacio\SetupWizard\Installer\Intercept::arm();
}
