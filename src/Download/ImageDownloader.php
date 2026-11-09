<?php

declare(strict_types=1);

namespace Shinigami\Download;

use CurlHandle;
use SplQueue;
use Shinigami\Support\Cancellation;
use Shinigami\Support\FileSystem;
use Shinigami\Support\Logger;

/**
 * Concurrent image downloader built on curl_multi.
 *
 * The pool stays full: every time one transfer finishes another queued job is
 * started immediately. Failed transfers are requeued with exponential
 * backoff instead of blocking the whole pool.
 */
final class ImageDownloader
{
    private bool $stagingCleaned = false;

    /** @var array<string, bool> */
    private array $deviceCache = [];

    public function __construct(
        private readonly string $userAgent,
        private readonly string $referer,
        private readonly ?string $stagingDir,
        private readonly bool $fastMove,
        private readonly int $concurrency,
        private readonly int $retries,
        private readonly int $retryDelayMs,
        private readonly int $timeout,
        private readonly int $connectTimeout,
        private readonly int $minBytes,
        private readonly Logger $logger,
        private readonly Cancellation $cancellation,
    ) {
    }

    /**
     * @param list<DownloadJob> $jobs
     * @param callable(DownloadResult): void|null $onResult
     */
    public function download(array $jobs, ?callable $onResult = null): void
    {
        $onResult ??= static function (DownloadResult $result): void {
        };

        $queue = new SplQueue();
        $seen = [];

        if ($this->stagingDir !== null) {
            FileSystem::ensureDir($this->stagingDir);
        }

        $this->cleanStaging();

        foreach ($jobs as $job) {
            if (isset($seen[$job->destination])) {
                continue;
            }

            $seen[$job->destination] = true;

            if ($this->isComplete($job->destination)) {
                $size = (int) @filesize($job->destination);
                $onResult(new DownloadResult($job, true, true, $size));
                continue;
            }

            $queue->enqueue(['job' => $job, 'attempt' => 0]);
        }

        if ($queue->isEmpty()) {
            return;
        }

        $multi = curl_multi_init();
        $active = [];
        $retryDelayUntil = 0.0;
        $stopped = false;
        $concurrency = max(1, $this->concurrency);

        while (true) {
            if ($this->cancellation->isRequested()) {
                $stopped = true;
            }

            if (!$stopped) {
                while (
                    count($active) < $concurrency
                    && !$queue->isEmpty()
                    && microtime(true) >= $retryDelayUntil
                ) {
                    /** @var array{job: DownloadJob, attempt: int} $entry */
                    $entry = $queue->dequeue();
                    $job = $entry['job'];

                    FileSystem::ensureDir(dirname($job->destination));
                    $tempPath = $this->tempPathFor($job->destination);
                    $handle = @fopen($tempPath, 'wb');

                    if ($handle === false) {
                        $onResult(new DownloadResult($job, false, false, 0, 'unable to open temporary file'));

                        continue;
                    }

                    $curl = $this->createHandle($job->url, $handle);
                    curl_multi_add_handle($multi, $curl);
                    $active[(int) $curl] = [
                        'job' => $job,
                        'handle' => $curl,
                        'file' => $handle,
                        'temp' => $tempPath,
                        'attempt' => $entry['attempt'],
                    ];
                }
            }

            if ($active === []) {
                if ($queue->isEmpty() || $stopped) {
                    break;
                }

                $sleep = max(0.01, min(0.25, $retryDelayUntil - microtime(true)));
                usleep((int) ($sleep * 1_000_000));

                continue;
            }

            do {
                $status = curl_multi_exec($multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            while (($info = curl_multi_info_read($multi)) !== false) {
                /** @var array{job: DownloadJob, handle: CurlHandle, file: resource, temp: string, attempt: int} $meta */
                $meta = $active[(int) $info['handle']] ?? null;

                if ($meta === null) {
                    curl_multi_remove_handle($multi, $info['handle']);
                    curl_close($info['handle']);
                    continue;
                }

                unset($active[(int) $info['handle']]);
                curl_multi_remove_handle($multi, $info['handle']);

                $curlErrno = (int) $info['result'];
                $statusCode = (int) curl_getinfo($info['handle'], CURLINFO_RESPONSE_CODE);
                $contentType = (string) curl_getinfo($info['handle'], CURLINFO_CONTENT_TYPE);
                curl_close($info['handle']);
                fclose($meta['file']);

                $part = $meta['temp'];
                $finalPart = $meta['job']->destination . '.part';
                $size = is_file($part) ? (int) filesize($part) : 0;
                $valid = $curlErrno === 0
                    && $statusCode >= 200
                    && $statusCode < 300
                    && $size >= $this->minBytes
                    && FileSystem::isImage($part);

                if ($valid) {
                    if ($this->moveIntoPlace($part, $finalPart, $meta['job']->destination)) {
                        $onResult(new DownloadResult($meta['job'], true, false, $size, null, $statusCode));
                        continue;
                    }

                    $error = 'unable to move completed download into place';
                } else {
                    $error = $this->describeFailure($curlErrno, $statusCode, $contentType, $size);
                }

                @unlink($part);
                @unlink($finalPart);

                $retryable = $this->isRetryable($curlErrno, $statusCode);

                if ($retryable && $meta['attempt'] < $this->retries) {
                    $queue->enqueue([
                        'job' => $meta['job'],
                        'attempt' => $meta['attempt'] + 1,
                    ]);
                    $delay = $this->retryDelayMs * (2 ** $meta['attempt']);
                    $retryDelayUntil = max($retryDelayUntil, microtime(true) + $delay / 1000);

                    $this->logger->debug('Image download failed, retrying', [
                        'url' => $meta['job']->url,
                        'status' => $statusCode,
                        'attempt' => $meta['attempt'] + 1,
                        'error' => $error,
                    ]);

                    continue;
                }

                $onResult(new DownloadResult(
                    $meta['job'],
                    false,
                    false,
                    0,
                    $error,
                    $statusCode,
                ));
            }

            if ($active !== []) {
                if (curl_multi_select($multi, 0.5) === -1) {
                    usleep(1000);
                }
            }
        }

        curl_multi_close($multi);
    }

    public function downloadOne(DownloadJob $job): DownloadResult
    {
        $result = null;

        $this->download([$job], static function (DownloadResult $downloadResult) use (&$result): void {
            $result = $downloadResult;
        });

        return $result ?? new DownloadResult($job, false, false, 0, 'job was not processed');
    }

    private function tempPathFor(string $destination): string
    {
        if ($this->stagingDir === null) {
            return $destination . '.part';
        }

        return $this->stagingDir . '/' . hash('xxh128', $destination) . '.part';
    }

    private function moveIntoPlace(string $part, string $finalPart, string $destination): bool
    {
        if ($this->stagingDir === null) {
            return @rename($part, $destination);
        }

        $moved = $this->isCrossDevice($part, $finalPart)
            ? ($this->fastMove && $this->fastMoveFile($part, $finalPart))
            : @rename($part, $finalPart);

        if (!$moved && !@rename($part, $finalPart)) {
            if (!@copy($part, $finalPart)) {
                return false;
            }

            @unlink($part);
        }

        if (!@rename($finalPart, $destination)) {
            @unlink($finalPart);

            return false;
        }

        return true;
    }

    private function isCrossDevice(string $a, string $b): bool
    {
        $dirA = dirname($a);
        $dirB = dirname($b);
        $key = $dirA . "\0" . $dirB;

        if (array_key_exists($key, $this->deviceCache)) {
            return $this->deviceCache[$key];
        }

        $statA = @stat($dirA);
        $statB = @stat($dirB);

        return $this->deviceCache[$key] = $statA !== false
            && $statB !== false
            && ($statA['dev'] ?? null) !== ($statB['dev'] ?? null);
    }

    /**
     * Native `mv` uses sendfile/copy_file_range and is ~5x faster than
     * PHP's stream copy on WSL DrvFs. Falls back to rename/copy elsewhere.
     */
    private function fastMoveFile(string $from, string $to): bool
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('exec')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        if (in_array('exec', $disabled, true)) {
            return false;
        }

        $command = 'mv -f -- ' . escapeshellarg($from) . ' ' . escapeshellarg($to) . ' 2>/dev/null';
        $output = [];
        $code = 0;

        @exec($command, $output, $code);

        if ($code === 0 && !is_file($from) && is_file($to)) {
            return true;
        }

        return false;
    }

    private function cleanStaging(): void
    {
        if ($this->stagingCleaned || $this->stagingDir === null) {
            return;
        }

        $this->stagingCleaned = true;
        $cutoff = time() - 3600;

        foreach (glob($this->stagingDir . '/*.part') ?: [] as $file) {
            $mtime = @filemtime($file);

            if ($mtime !== false && $mtime < $cutoff) {
                @unlink($file);
            }
        }
    }

    private function isComplete(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        clearstatcache(true, $path);

        return (int) filesize($path) >= $this->minBytes;
    }

    /**
     * @param resource $file
     */
    private function createHandle(string $url, $file): CurlHandle
    {
        $handle = curl_init();

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $file,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 30,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_REFERER => $this->referer,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                'Accept-Language: id-ID,id;q=0.9,en;q=0.8',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);

        return $handle;
    }

    private function describeFailure(int $curlErrno, int $status, string $contentType, int $size): string
    {
        if ($curlErrno !== 0) {
            return 'curl error ' . $curlErrno . ': ' . curl_strerror($curlErrno);
        }

        if ($status < 200 || $status >= 300) {
            return 'HTTP ' . ($status ?: 'unknown');
        }

        if ($size < $this->minBytes) {
            return sprintf('response too small (%d bytes)', $size);
        }

        return 'response is not a valid image' . ($contentType !== '' ? ' (' . $contentType . ')' : '');
    }

    private function isRetryable(int $curlErrno, int $status): bool
    {
        if ($curlErrno !== 0 || $status === 0) {
            return true;
        }

        if ($status === 408 || $status === 425 || $status === 429) {
            return true;
        }

        if ($status >= 500) {
            return true;
        }

        // A 2xx response that failed validation is usually a Cloudflare
        // challenge or a truncated transfer, so give it another chance.
        return $status >= 200 && $status < 300;
    }
}
