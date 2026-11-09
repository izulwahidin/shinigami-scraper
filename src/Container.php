<?php

declare(strict_types=1);

namespace Shinigami;

use Shinigami\Api\ShinigamiApi;
use Shinigami\Download\ImageDownloader;
use Shinigami\Http\CurlHttpClient;
use Shinigami\Repository\ChapterRepository;
use Shinigami\Repository\ProjectRepository;
use Shinigami\Repository\RunRepository;
use Shinigami\Scraper\SyncService;
use Shinigami\Support\Cancellation;
use Shinigami\Support\Lock;
use Shinigami\Support\Logger;
use Shinigami\Support\Output;
use Shinigami\Support\PathBuilder;

final class Container
{
    private ?Database $database = null;
    private ?Logger $logger = null;
    private ?CurlHttpClient $httpClient = null;
    private ?ShinigamiApi $api = null;
    private ?ProjectRepository $projects = null;
    private ?ChapterRepository $chapters = null;
    private ?RunRepository $runs = null;
    private ?ImageDownloader $downloader = null;
    private ?PathBuilder $paths = null;
    private ?SyncService $syncService = null;
    private ?Lock $lock = null;
    private ?Cancellation $cancellation = null;

    public function __construct(
        public readonly Config $config,
        public readonly Output $output,
    ) {
    }

    public function database(): Database
    {
        return $this->database ??= new Database($this->config->databasePath(), $this->config->schemaPath());
    }

    public function logger(): Logger
    {
        return $this->logger ??= new Logger(
            $this->config->logPath(),
            $this->output,
            $this->config->logLevel,
            $this->config->logMaxBytes,
        );
    }

    public function cancellation(): Cancellation
    {
        return $this->cancellation ??= new Cancellation();
    }

    public function httpClient(): CurlHttpClient
    {
        return $this->httpClient ??= new CurlHttpClient(
            $this->config->apiBaseUrl,
            $this->config->apiUserAgent,
            $this->config->apiReferer,
            $this->config->apiTimeout,
            $this->config->apiConnectTimeout,
            $this->config->apiRetries,
            $this->config->apiRetryDelayMs,
            $this->config->apiDelayMs,
            $this->logger(),
        );
    }

    public function api(): ShinigamiApi
    {
        return $this->api ??= new ShinigamiApi($this->httpClient(), $this->logger());
    }

    public function projects(): ProjectRepository
    {
        return $this->projects ??= new ProjectRepository($this->database()->pdo());
    }

    public function chapters(): ChapterRepository
    {
        return $this->chapters ??= new ChapterRepository($this->database()->pdo());
    }

    public function runs(): RunRepository
    {
        return $this->runs ??= new RunRepository($this->database()->pdo());
    }

    public function paths(): PathBuilder
    {
        return $this->paths ??= new PathBuilder($this->config->downloadDir);
    }

    public function downloader(): ImageDownloader
    {
        return $this->downloader ??= new ImageDownloader(
            $this->config->apiUserAgent,
            $this->config->apiReferer,
            $this->config->downloadStagingDir,
            $this->config->downloadFastMove,
            $this->config->downloadConcurrency,
            $this->config->downloadRetries,
            $this->config->downloadRetryDelayMs,
            $this->config->downloadTimeout,
            $this->config->downloadConnectTimeout,
            $this->config->minImageBytes,
            $this->logger(),
            $this->cancellation(),
        );
    }

    public function syncService(): SyncService
    {
        return $this->syncService ??= new SyncService(
            $this->config,
            $this->api(),
            $this->projects(),
            $this->chapters(),
            $this->runs(),
            $this->downloader(),
            $this->paths(),
            $this->logger(),
            $this->cancellation(),
        );
    }

    public function lock(): Lock
    {
        return $this->lock ??= new Lock($this->config->lockPath());
    }
}
