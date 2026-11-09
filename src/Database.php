<?php

declare(strict_types=1);

namespace Shinigami;

use PDO;
use RuntimeException;

final class Database
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $path,
        private readonly string $schemaPath,
    ) {
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dir = dirname($this->path);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Unable to create database directory: %s', $dir));
        }

        $pdo = new PDO('sqlite:' . $this->path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA busy_timeout = 10000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA temp_store = MEMORY');

        $this->pdo = $pdo;
        $this->migrate();

        return $this->pdo;
    }

    /**
     * @template T
     * @param callable(PDO): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $result = $callback($pdo);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    private function migrate(): void
    {
        $schema = file_get_contents($this->schemaPath);

        if ($schema === false) {
            throw new RuntimeException(sprintf('Unable to read schema file: %s', $this->schemaPath));
        }

        $this->pdo?->exec($schema);
        $this->pdo?->exec('PRAGMA user_version = 1');
    }
}
