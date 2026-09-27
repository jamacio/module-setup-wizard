<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Installer;

use PDOException;
use Throwable;

/**
 * Front controller of the Setup Wizard, called by Intercept from Magento's index.php.
 *
 *  GET               page (form, progress or "already installed")
 *  GET  ?action=status&job=ID&offset=N   progress as JSON
 *  POST action=test  connectivity check of database, search engine and Redis
 *  POST action=start validates, prepares and launches the installation job
 *
 * Once app/etc/env.php has an install date the wizard only shows the result and
 * refuses to change anything, like the original Magento 2.0 wizard.
 */
final class App
{
    private const SESSION_NAME = 'JAMACIO_SETUP_WIZARD';
    private const LANGUAGE_COOKIE = 'setup_wizard_lang';

    private readonly EnvFile $env;
    private readonly JobManager $jobs;
    private Translator $translator;

    public function __construct(private readonly Paths $paths)
    {
        $this->env = new EnvFile($paths->envFile());
        $this->jobs = new JobManager($paths);
    }

    public function run(): void
    {
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');

        session_name(self::SESSION_NAME);
        session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict']);
        $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
        $this->translator = Translator::activate($this->resolveLocale());

        $action = (string) ($_REQUEST['action'] ?? 'page');
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

        try {
            match (true) {
                $action === 'status' => $this->status(),
                $isPost && $action === 'test' => $this->guardedPost(fn () => $this->test()),
                $isPost && $action === 'start' => $this->guardedPost(fn () => $this->start()),
                default => $this->page(),
            };
        } catch (Throwable $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function page(): void
    {
        $requestedJob = (string) ($_GET['job'] ?? '');
        $jobId = $this->jobs->isValidId($requestedJob) ? $requestedJob : $this->jobs->currentId();
        // Progress and log are only shown to the browser that started the run.
        $job = $jobId && $this->jobs->isOwnedByRequest($jobId) ? $this->jobs->status($jobId) : null;
        $installed = $this->env->isInstalled();

        $view = match (true) {
            $job !== null && ($requestedJob !== '' || in_array($job['state'], [JobManager::STATE_QUEUED, JobManager::STATE_RUNNING], true)) => 'job',
            $this->jobs->isBusy() => 'busy',
            $installed => 'installed',
            default => 'form',
        };

        $requirements = $view === 'form' ? Requirements::check($this->paths) : [];
        $context = [
            'view' => $view,
            'csrf' => $_SESSION['csrf'],
            'job' => $job === null ? null : array_diff_key($job, ['log' => true, 'offset' => true]),
            'requirements' => $requirements,
            'requirementsPassed' => Requirements::passed($requirements),
            'defaults' => $view === 'form' ? Defaults::detect($_SERVER, $this->env->read(), $this->translator->locale()) : [],
            'installedUrls' => $installed ? $this->installedUrls() : null,
            'searchEngines' => Input::SEARCH_ENGINES,
            'sampleDataAvailable' => Planner::sampleDataModules() !== [],
            'storeLocales' => $view === 'form' ? [
                'languages' => StoreLocaleOptions::languages($this->translator->locale()),
                'currencies' => StoreLocaleOptions::currencies($this->translator->locale()),
                'timezones' => StoreLocaleOptions::timezones($this->translator->locale()),
            ] : [],
            'locale' => $this->translator->locale(),
            'languages' => Translator::available(),
            'translator' => $this->translator,
        ];

        header('Content-Type: text/html; charset=UTF-8');
        (static function (array $context, string $template): void {
            extract($context);
            require $template;
        })($context, $this->paths->view('wizard.phtml'));
    }

    private function status(): void
    {
        $id = (string) ($_GET['job'] ?? '');
        $status = $this->jobs->isOwnedByRequest($id) ? $this->jobs->status($id, (int) ($_GET['offset'] ?? 0)) : null;
        $status === null
            ? $this->json(['ok' => false, 'message' => (string) __('Run not found.')], 404)
            : $this->json(['ok' => true] + $status);
    }

    private function guardedPost(callable $handler): void
    {
        if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
            $this->json(['ok' => false, 'message' => (string) __('Your session expired. Reload the page.')], 403);
            return;
        }
        if ($this->env->isInstalled()) {
            $this->json(['ok' => false, 'message' => (string) __('Magento is already installed; the wizard is locked.')], 409);
            return;
        }
        if ($this->jobs->isBusy()) {
            $this->json(['ok' => false, 'message' => (string) __('An installation is already running.')], 409);
            return;
        }
        $handler();
    }

    private function test(): void
    {
        $in = Input::normalize($_POST);
        $results = ['db' => Database::inspect($in['db'])];
        if ($in['search']['engine'] !== Input::SEARCH_ENGINE_MYSQL && $in['search']['host'] !== '') {
            $results['search'] = ServiceProbe::search($in['search']['engine'], $in['search']['host'], $in['search']['port']);
        }
        if ($in['redis']['enabled']) {
            $results['redis'] = ServiceProbe::redis($in['redis']['host'], $in['redis']['port']);
        }

        $this->json(['ok' => true, 'results' => $results]);
    }

    private function start(): void
    {
        if (!Requirements::passed(Requirements::check($this->paths))) {
            $this->json(['ok' => false, 'message' => (string) __('The environment does not meet the requirements. Reload the page for details.')], 422);
            return;
        }

        $in = Input::normalize($_POST);
        $errors = Input::validate($in);
        if ($errors !== []) {
            $this->json(['ok' => false, 'message' => (string) __('Fix the highlighted fields.'), 'errors' => $errors], 422);
            return;
        }

        $preamble = [];
        $database = Database::inspect($in['db']);
        if (!$database['ok']) {
            $this->json(['ok' => false, 'message' => $database['message'], 'errors' => ['db.host' => $database['message']]], 422);
            return;
        }

        if ($in['mode'] === Input::MODE_NEW) {
            if (!$database['exists']) {
                try {
                    Database::createDatabase($in['db']);
                    $preamble[] = (string) __('Database "%1" created.', $in['db']['name']);
                } catch (PDOException $e) {
                    $message = (string) __('The database does not exist and the user cannot create it: %1', Database::describe($e));
                    $this->json(['ok' => false, 'message' => $message, 'errors' => ['db.name' => $message]], 422);
                    return;
                }
            } elseif ($database['tables'] > 0 && !$in['options']['cleanup_db']) {
                $message = (string) __(
                    'The database already has %1 tables. Tick "Drop existing tables" or use the "Use an existing database" mode.',
                    $database['tables']
                );
                $this->json(['ok' => false, 'message' => $message, 'errors' => ['options.cleanup_db' => $message]], 422);
                return;
            }
            if ($in['search']['engine'] === Input::SEARCH_ENGINE_MYSQL) {
                // setup:install rejects unknown --search-engine values, but reads catalog/search/engine
                // from env.php before validating it; save-mysql-search.php moves it to the database afterwards.
                $this->env->write(array_replace_recursive(
                    $this->env->read(),
                    ['system' => ['default' => ['catalog' => ['search' => ['engine' => Input::SEARCH_ENGINE_MYSQL]]]]]
                ));
                $preamble[] = (string) __('MySQL search engine set in app/etc/env.php for the installation.');
            }
        } else {
            if (!$database['exists'] || !$database['magento']) {
                $message = $database['exists']
                    ? (string) __('This database has no Magento tables (check the table prefix).')
                    : (string) __('Database "%1" does not exist.', $in['db']['name']);
                $this->json(['ok' => false, 'message' => $message, 'errors' => ['db.name' => $message]], 422);
                return;
            }

            $preamble = StoreSettings::apply($in);
            $backup = $this->env->write(EnvFile::buildForExistingDatabase($in, $this->paths->root(), $this->env->read()));
            $preamble[] = $backup
                ? (string) __('app/etc/env.php written (previous file saved as %1).', basename($backup))
                : (string) __('app/etc/env.php written.');
            if ($in['crypt_key'] === '') {
                $preamble[] = (string) __(
                    'Warning: without the original encryption key, encrypted values in the database (integration, payment and SMTP credentials) must be configured again.'
                );
            }
        }

        $php = PhpCli::info()['binary'];
        ['id' => $id, 'token' => $token] = $this->jobs->start(
            (new Planner($this->paths, $php))->plan($in),
            Planner::secrets($in),
            $preamble,
            [
                'mode' => $in['mode'],
                'store_url' => $in['base_url'],
                'admin_url' => $in['base_url'] . $in['backend_frontname'] . '/',
                'admin_user' => $in['admin']['enabled'] ? $in['admin']['username'] : null,
                'locale' => $this->translator->locale(),
            ],
            $php
        );

        setcookie(JobManager::OWNER_COOKIE, $id . ':' . $token, [
            'expires' => time() + 86400,
            'path' => '/',
            'secure' => ($_SERVER['HTTPS'] ?? 'off') !== 'off' && !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        $this->json(['ok' => true, 'job' => $id]);
    }

    /**
     * @return array{store: ?string, admin: ?string}
     */
    private function installedUrls(): array
    {
        $env = $this->env->read();
        $store = null;
        try {
            $db = $env['db']['connection']['default'] ?? [];
            $pdo = Database::connect([
                'host' => (string) ($db['host'] ?? ''),
                'name' => (string) ($db['dbname'] ?? ''),
                'user' => (string) ($db['username'] ?? ''),
                'password' => (string) ($db['password'] ?? ''),
                'prefix' => '',
            ]);
            $store = Database::configValue($pdo, (string) ($env['db']['table_prefix'] ?? ''), 'web/unsecure/base_url');
        } catch (Throwable) {
            // Links are a convenience; the page still renders without them.
        }
        $frontName = $env['backend']['frontName'] ?? null;

        return [
            'store' => $store,
            'admin' => $store && $frontName ? $store . $frontName . '/' : null,
        ];
    }

    /**
     * Wizard language: ?lang= (remembered in a cookie), then the cookie, then English.
     */
    private function resolveLocale(): string
    {
        $available = Translator::available();
        $requested = (string) ($_GET['lang'] ?? '');
        if (isset($available[$requested])) {
            setcookie(self::LANGUAGE_COOKIE, $requested, [
                'expires' => time() + 31536000,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            return $requested;
        }
        $remembered = (string) ($_COOKIE[self::LANGUAGE_COOKIE] ?? '');

        return isset($available[$remembered]) ? $remembered : Translator::SOURCE_LOCALE;
    }

    private function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
