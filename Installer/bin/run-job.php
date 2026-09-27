<?php
/**
 * Copyright © Jamacio. All rights reserved.
 *
 * Background runner started by the Setup Wizard: php run-job.php <job-id>
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

set_time_limit(0);
ignore_user_abort(true);

$root = require dirname(__DIR__) . '/bootstrap.php';
$jobs = new \Jamacio\SetupWizard\Installer\JobManager(new \Jamacio\SetupWizard\Installer\Paths($root));

$id = (string) ($argv[1] ?? '');
if (!$jobs->isValidId($id)) {
    fwrite(STDERR, 'Usage: php run-job.php <job-id>' . PHP_EOL);
    exit(1);
}

exit($jobs->run($id));
