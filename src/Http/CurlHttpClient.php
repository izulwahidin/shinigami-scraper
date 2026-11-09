<?php

declare(strict_types=1);

namespace Shinigami\Http;

use CurlHandle;
use Shinigami\Support\Logger;

/**
 * Small curl wrapper with retries, exponential backoff and a concurrent
 * JSON mode used to fetch many chapter detail payloads in parallel.
 */
final class CurlHttpClient
{
    private const JSON_HEADERS = [
        'Accept: application/json, text/plain, */*',
        'Accept-Language: id-ID,id;q=0.9,en;q=0.8',
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $userAgent,
        private readonly string $referer,
        private readonly int $timeout,
        private readonly int $connectTimeout,
        private readonly int $retries,
        private readonly int $retryDelayMs,
        private readonly int $delayMs,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    public function getJson(string $path, array $query = []): array
    {
        $url = $this->buildUrl($path, $query);
        $attempt = 0;
        $maxAttempts = $this->retries + 1;

        while (true) {
            $attempt++;

            if ($this->delayMs > 0) {
                usleep($this->delayMs * 1000);
            }

            [$body, $status, $error] = $this->executeSingle($url);
            $result = $this->decode($body, $status, $error);

            if ($result['ok']) {
                return $result['data'];
            }

            if ($result['retryable'] && $attempt < $maxAttempts) {
                $this->logger->warn('API request failed, retrying', [
                    'url' => $url,
                    'status' => $status,
                    'attempt' => $attempt,
                    'error' => $result['error'],
                ]);
                $this->sleepBackoff($attempt);

                continue;
            }

            throw new HttpException(
                sprintf('API request failed (%s): %s', $status ?: 'network', $result['error']),
                $status,
                $url,
                $result['retryable'],
            );
        }
    }

    /**
     * Fetch many JSON documents concurrently.
     *
     * @param array<string, string> $paths Map of caller key => API path.
     * @return array<string, array{ok: bool, status: int, data?: array<string, mixed>, error?: string}>
     */
    public function getJsonMany(array $paths, int $concurrency = 8): array
    {
        $pending = [];

        foreach ($paths as $key => $path) {
            $pending[$key] = $this->buildUrl($path);
        }

        $results = [];
        $attempts = [];
        $maxAttempts = $this->retries + 1;

        while ($pending !== []) {
            $retry = [];

            foreach (array_chunk($pending, max(1, $concurrency), true) as $chunk) {
                $multi = curl_multi_init();
                $handles = [];

                foreach ($chunk as $key => $url) {
                    $handle = $this->createHandle($url);
                    curl_multi_add_handle($multi, $handle);
                    $handles[(int) $handle] = ['key' => $key, 'handle' => $handle, 'url' => $url];
                }

                $active = null;
                do {
                    $status = curl_multi_exec($multi, $active);
                } while ($status === CURLM_CALL_MULTI_PERFORM);

                while ($active > 0) {
                    if (curl_multi_select($multi, 1.0) === -1) {
                        usleep(1000);
                    }

                    do {
                        $status = curl_multi_exec($multi, $active);
                    } while ($status === CURLM_CALL_MULTI_PERFORM);
                }

                foreach ($handles as $meta) {
                    $handle = $meta['handle'];
                    $body = curl_multi_getcontent($handle);
                    $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                    $error = curl_error($handle);
                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);

                    $key = $meta['key'];
                    $attempts[$key] = ($attempts[$key] ?? 0) + 1;
                    $decoded = $this->decode($body, $statusCode, $error);

                    if ($decoded['ok']) {
                        $results[$key] = $decoded;
                        continue;
                    }

                    if ($decoded['retryable'] && $attempts[$key] < $maxAttempts) {
                        $retry[$key] = $pending[$key];
                        continue;
                    }

                    unset($decoded['retryable']);
                    $results[$key] = $decoded;
                    $this->logger->debug('Concurrent API request failed permanently', [
                        'url' => $meta['url'],
                        'status' => $statusCode,
                        'error' => $decoded['error'],
                    ]);
                }

                curl_multi_close($multi);
            }

            $pending = $retry;

            if ($pending !== []) {
                $this->sleepBackoff(max($attempts));
            }
        }

        return $results;
    }

    public function buildUrl(string $path, array $query = []): string
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /**
     * @return array{0: string|null, 1: int, 2: string}
     */
    private function executeSingle(string $url): array
    {
        $handle = $this->createHandle($url);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        return [is_string($body) ? $body : null, $status, $error];
    }

    private function createHandle(string $url): CurlHandle
    {
        $handle = curl_init();

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_REFERER => $this->referer,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => self::JSON_HEADERS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);

        return $handle;
    }

    /**
     * @return array{ok: bool, status: int, data?: array<string, mixed>, error?: string, retryable?: bool}
     */
    private function decode(?string $body, int $status, string $error): array
    {
        if ($error !== '' || $status === 0) {
            return [
                'ok' => false,
                'status' => $status,
                'error' => $error !== '' ? $error : 'empty response',
                'retryable' => true,
            ];
        }

        if ($status === 429 || $status >= 500) {
            return [
                'ok' => false,
                'status' => $status,
                'error' => 'HTTP ' . $status,
                'retryable' => true,
            ];
        }

        if ($status < 200 || $status >= 300) {
            return [
                'ok' => false,
                'status' => $status,
                'error' => 'HTTP ' . $status,
                'retryable' => false,
            ];
        }

        if ($body === null || $body === '') {
            return [
                'ok' => false,
                'status' => $status,
                'error' => 'empty body',
                'retryable' => true,
            ];
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'status' => $status,
                'error' => 'invalid JSON: ' . substr(trim($body), 0, 120),
                'retryable' => true,
            ];
        }

        if (array_key_exists('retcode', $decoded) && (int) $decoded['retcode'] !== 0) {
            return [
                'ok' => false,
                'status' => $status,
                'error' => (string) ($decoded['message'] ?? ('retcode ' . $decoded['retcode'])),
                'retryable' => false,
            ];
        }

        return [
            'ok' => true,
            'status' => $status,
            'data' => $decoded,
        ];
    }

    private function sleepBackoff(int $attempt): void
    {
        $delay = $this->retryDelayMs * (2 ** max(0, $attempt - 1));
        $delay = min($delay, 30_000);
        usleep(($delay + random_int(0, 250)) * 1000);
    }
}
