<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Installer;

use RuntimeException;

/**
 * Runs the planned bin/magento commands in a detached CLI process, so the
 * installation survives the HTTP request, and exposes its progress to the page.
 *
 * Files in var/jamacio_setup_wizard/:
 *  - {id}.job.json    commands to run (contains passwords; 0600, deleted when the job ends)
 *  - {id}.status.json state of the job and of each step
 *  - {id}.log         combined output of the commands
 *  - current          id of the latest job
 *
 * Only the browser that started a run can see it: start() returns a random token that App puts
 * in an HttpOnly cookie, and status.json keeps just its SHA-256 hash. The log (database names,
 * paths, commands) is never readable with the job id alone.
 */
final class JobManager
{
    public const STATE_QUEUED = 'queued';
    public const STATE_RUNNING = 'running';
    public const STATE_SUCCESS = 'success';
    public const STATE_FAILED = 'failed';

    private const ID_PATTERN = '/^\d{14}-[a-f0-9]{8}$/';
    private const MAX_LOG_CHUNK = 262144;
    private const START_TIMEOUT = 20;

    /**
     * "<job id>:<token>" of the run started by this browser.
     */
    public const OWNER_COOKIE = 'setup_wizard_job';

    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * @param list<array{label: string, argv: list<string>}> $steps
     * @param list<string> $secrets Values masked in the log.
     * @param list<string> $preamble Lines written at the top of the log (work already done synchronously).
     * @param array $meta Extra data for the page (mode, store and admin URLs).
     * @return array{id: string, token: string} The token identifies the browser that owns the run.
     */
    public function start(array $steps, array $secrets, array $preamble, array $meta, string $php): array
    {
        $dir = $this->paths->workDir();
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException((string) __('Could not create %1', $dir));
        }

        $id = date('YmdHis') . '-' . bin2hex(random_bytes(4));
        $token = bin2hex(random_bytes(32));
        $jobFile = $this->file($id, 'job.json');
        file_put_contents($jobFile, json_encode(['steps' => $steps, 'secrets' => $secrets]), LOCK_EX);
        chmod($jobFile, 0600);

        $log = '';
        foreach ($preamble as $line) {
            $log .= '✔ ' . $line . PHP_EOL;
        }
        file_put_contents($this->file($id, 'log'), $log);

        $this->saveStatus($id, [
            'id' => $id,
            'state' => self::STATE_QUEUED,
            'current' => -1,
            'steps' => array_map(static fn (array $step): array => ['label' => $step['label'], 'state' => 'pending'], $steps),
            'created_at' => time(),
            'started_at' => null,
            'finished_at' => null,
            'pid' => null,
            'error' => null,
            'meta' => $meta,
            'owner_hash' => hash('sha256', $token),
        ]);
        file_put_contents($dir . '/current', $id, LOCK_EX);

        // setsid detaches the runner from the PHP-FPM worker, so it is not tied to this request.
        $prefix = Shell::has('setsid') ? 'setsid ' : (Shell::has('nohup') ? 'nohup ' : '');
        exec(sprintf(
            '%s%s %s %s > /dev/null 2>&1 &',
            $prefix,
            escapeshellarg($php),
            escapeshellarg($this->paths->runner()),
            escapeshellarg($id)
        ));

        return ['id' => $id, 'token' => $token];
    }

    /**
     * Whether the owner cookie of this request belongs to the run. Runs without an owner
     * (created by older versions) belong to nobody.
     */
    public function isOwnedByRequest(string $id): bool
    {
        [$cookieId, $token] = array_pad(explode(':', (string) ($_COOKIE[self::OWNER_COOKIE] ?? ''), 2), 2, '');
        $status = $cookieId === $id ? $this->loadStatus($id) : null;

        return $status !== null
            && is_string($status['owner_hash'] ?? null)
            && $token !== ''
            && hash_equals($status['owner_hash'], hash('sha256', $token));
    }

    /**
     * Status plus the log output produced after $offset bytes.
     */
    public function status(string $id, int $offset = 0): ?array
    {
        $status = $this->loadStatus($id);
        if ($status === null) {
            return null;
        }
        $status = $this->detectDeadRunner($status);

        $logFile = $this->file($id, 'log');
        $size = is_file($logFile) ? (int) filesize($logFile) : 0;
        $offset = max(0, min($offset, $size));
        $chunk = '';
        if ($size > $offset) {
            $handle = fopen($logFile, 'rb');
            fseek($handle, $offset);
            $chunk = (string) fread($handle, self::MAX_LOG_CHUNK);
            fclose($handle);
        }
        unset($status['pid'], $status['owner_hash']);

        return $status + ['log' => $chunk, 'offset' => $offset + strlen($chunk)];
    }

    public function currentId(): ?string
    {
        $file = $this->paths->workDir() . '/current';
        $id = is_file($file) ? trim((string) file_get_contents($file)) : '';

        return $this->isValidId($id) && is_file($this->file($id, 'status.json')) ? $id : null;
    }

    public function isBusy(): bool
    {
        $id = $this->currentId();
        $status = $id ? $this->status($id) : null;

        return $status !== null && in_array($status['state'], [self::STATE_QUEUED, self::STATE_RUNNING], true);
    }

    /**
     * Whether the run is still going or finished less than $seconds ago.
     */
    public function isRecent(string $id, int $seconds): bool
    {
        $status = $this->loadStatus($id);
        if ($status === null) {
            return false;
        }

        return in_array($status['state'], [self::STATE_QUEUED, self::STATE_RUNNING], true)
            || time() - (int) ($status['finished_at'] ?? 0) < $seconds;
    }

    public function isValidId(string $id): bool
    {
        return (bool) preg_match(self::ID_PATTERN, $id);
    }

    /**
     * Executes the job. Called by bin/run-job.php in the detached CLI process.
     */
    public function run(string $id): int
    {
        $jobFile = $this->file($id, 'job.json');
        $job = is_file($jobFile) ? json_decode((string) file_get_contents($jobFile), true) : null;
        $status = $this->loadStatus($id);
        if (!is_array($job) || $status === null || $status['state'] !== self::STATE_QUEUED) {
            return 1;
        }
        // Secrets only live in memory from here on.
        @unlink($jobFile);
        Translator::activate((string) ($status['meta']['locale'] ?? Translator::SOURCE_LOCALE));

        $status['state'] = self::STATE_RUNNING;
        $status['started_at'] = time();
        $status['pid'] = getmypid();
        $this->saveStatus($id, $status);

        $logFile = $this->file($id, 'log');
        $mask = static fn (string $text): string => $job['secrets'] === [] ? $text : str_replace($job['secrets'], '******', $text);

        foreach ($job['steps'] as $index => $step) {
            $status['current'] = $index;
            $status['steps'][$index]['state'] = self::STATE_RUNNING;
            $this->saveStatus($id, $status);

            file_put_contents(
                $logFile,
                PHP_EOL . sprintf('==> [%s] %s', date('H:i:s'), $step['label']) . PHP_EOL
                    . '$ ' . $mask($this->describeCommand($step['argv'])) . PHP_EOL,
                FILE_APPEND
            );

            $exitCode = $this->exec($step['argv'], $step['env'] ?? [], $logFile, $mask);
            $status['steps'][$index]['state'] = $exitCode === 0 ? self::STATE_SUCCESS : self::STATE_FAILED;

            if ($exitCode !== 0) {
                $status['state'] = self::STATE_FAILED;
                $status['error'] = (string) __('"%1" exited with code %2. See the log below.', $step['label'], $exitCode);
                break;
            }
        }

        if ($status['state'] === self::STATE_RUNNING) {
            $status['state'] = self::STATE_SUCCESS;
        }
        $status['current'] = -1;
        $status['finished_at'] = time();
        $this->saveStatus($id, $status);
        file_put_contents(
            $logFile,
            PHP_EOL . sprintf(
                '==> [%s] %s',
                date('H:i:s'),
                $status['state'] === self::STATE_SUCCESS ? __('Completed successfully.') : __('Failed.')
            ) . PHP_EOL,
            FILE_APPEND
        );

        return $status['state'] === self::STATE_SUCCESS ? 0 : 1;
    }

    /**
     * Command line as shown in the log, relative to the Magento root.
     */
    private function describeCommand(array $argv): string
    {
        // bin/magento steps are [php, -d, memory_limit=-1, bin/magento, ...arguments] (see Planner::magento()).
        $isMagento = ($argv[3] ?? '') === $this->paths->magentoBin();
        $script = str_replace($this->paths->root() . '/', '', $isMagento ? 'bin/magento' : (string) ($argv[1] ?? ''));
        $arguments = array_slice($argv, $isMagento ? 4 : 2);

        return trim('php ' . $script . ' ' . implode(' ', array_map('escapeshellarg', $arguments)));
    }

    /**
     * Streams the command output into the log, masking secrets on the way.
     *
     * @param array<string, string> $env Variables added to the inherited environment.
     */
    private function exec(array $argv, array $env, string $logFile, callable $mask): int
    {
        $process = proc_open(
            $argv,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $this->paths->root(),
            $env === [] ? null : $env + getenv()
        );
        if (!is_resource($process)) {
            file_put_contents($logFile, __('Could not start the process.') . PHP_EOL, FILE_APPEND);
            return 127;
        }

        $log = fopen($logFile, 'ab');
        while (($line = fgets($pipes[1])) !== false) {
            fwrite($log, $mask($line));
            fflush($log);
        }
        fclose($log);
        fclose($pipes[1]);

        return proc_close($process);
    }

    /**
     * A job left "queued" or "running" by a runner that never started or was killed
     * would lock the wizard forever.
     */
    private function detectDeadRunner(array $status): array
    {
        $dead = match ($status['state']) {
            self::STATE_QUEUED => time() - (int) $status['created_at'] > self::START_TIMEOUT,
            self::STATE_RUNNING => $status['pid'] && is_dir('/proc') && !is_dir('/proc/' . (int) $status['pid']),
            default => false,
        };
        if (!$dead) {
            return $status;
        }

        $status['error'] = $status['state'] === self::STATE_QUEUED
            ? (string) __('The installation process did not start. Check that the PHP CLI can run %1', $this->paths->runner())
            : (string) __('The installation process was interrupted.');
        $status['state'] = self::STATE_FAILED;
        $status['finished_at'] = time();
        $this->saveStatus($status['id'], $status);
        @unlink($this->file($status['id'], 'job.json'));

        return $status;
    }

    private function loadStatus(string $id): ?array
    {
        if (!$this->isValidId($id)) {
            return null;
        }
        $file = $this->file($id, 'status.json');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) ? $data : null;
    }

    private function saveStatus(string $id, array $status): void
    {
        $file = $this->file($id, 'status.json');
        $tmp = $file . '.tmp';
        file_put_contents($tmp, json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        rename($tmp, $file);
    }

    private function file(string $id, string $suffix): string
    {
        return $this->paths->workDir() . '/' . $id . '.' . $suffix;
    }
}
