<?php
/**
 * Copyright © Jamacio. All rights reserved.
 */
declare(strict_types=1);

namespace Jamacio\SetupWizard\Installer;

/**
 * Connectivity checks for Redis and the search engine.
 */
final class ServiceProbe
{
    private const TIMEOUT = 3;

    /**
     * @return array{ok: bool, message: string}
     */
    public static function redis(string $host, int $port): array
    {
        $socket = @fsockopen($host, $port, $errno, $error, self::TIMEOUT);
        if ($socket === false) {
            return ['ok' => false, 'message' => (string) __('No connection to %1:%2 (%3).', $host, $port, $error)];
        }
        stream_set_timeout($socket, self::TIMEOUT);
        fwrite($socket, "PING\r\n");
        $reply = trim((string) fgets($socket));
        fclose($socket);

        return match (true) {
            $reply === '+PONG' => ['ok' => true, 'message' => (string) __('Redis replied PONG.')],
            str_starts_with($reply, '-NOAUTH') => ['ok' => false, 'message' => (string) __('Redis requires a password (not supported by this wizard).')],
            default => ['ok' => false, 'message' => (string) __('Unexpected Redis reply: %1', $reply ?: '(empty)')],
        };
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function search(string $engine, string $host, int $port): array
    {
        $base = str_contains($host, '://') ? rtrim($host, '/') : 'http://' . $host;
        $url = $base . ':' . $port . '/';
        $body = @file_get_contents($url, false, stream_context_create([
            'http' => ['timeout' => self::TIMEOUT, 'ignore_errors' => true],
        ]));
        if ($body === false) {
            return ['ok' => false, 'message' => (string) __('No response from %1.', $url)];
        }

        $info = json_decode($body, true);
        $number = $info['version']['number'] ?? null;
        if ($number === null) {
            return ['ok' => false, 'message' => (string) __('%1 responded, but it does not look like OpenSearch or Elasticsearch.', $url)];
        }

        $isOpenSearch = ($info['version']['distribution'] ?? '') === 'opensearch';
        $found = ($isOpenSearch ? 'OpenSearch ' : 'Elasticsearch ') . $number;
        if ($engine === 'opensearch' && !$isOpenSearch) {
            return ['ok' => false, 'message' => (string) __('Found %1, but the selected engine is OpenSearch.', $found)];
        }
        if ($engine === 'elasticsearch8' && ($isOpenSearch || (int) $number < 8)) {
            return ['ok' => false, 'message' => (string) __('Found %1, but the selected engine is Elasticsearch 8.', $found)];
        }

        return ['ok' => true, 'message' => (string) __('%1 is responding.', $found)];
    }
}
