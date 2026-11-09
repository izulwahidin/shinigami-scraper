<?php

declare(strict_types=1);

namespace Shinigami\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use FilesystemIterator;

final class FileSystem
{
    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Unable to create directory: %s', $dir));
        }
    }

    public static function atomicWrite(string $path, string $contents): void
    {
        self::ensureDir(dirname($path));

        $tmp = $path . '.tmp.' . getmypid();

        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new RuntimeException(sprintf('Unable to write file: %s', $tmp));
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException(sprintf('Unable to move temporary file into place: %s', $path));
        }
    }

    public static function atomicWriteJson(string $path, array $data): void
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($json === false) {
            throw new RuntimeException(sprintf('Unable to encode JSON for: %s', $path));
        }

        self::atomicWrite($path, $json . PHP_EOL);
    }

    public static function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($dir);
    }

    /**
     * Validate that a downloaded file really is an image before it is moved
     * into the final chapter folder. This prevents saving Cloudflare HTML
     * error pages as ".jpg" files.
     */
    public static function isImage(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 32);
        fclose($handle);

        if ($head === '') {
            return false;
        }

        return str_starts_with($head, "\xFF\xD8\xFF")                 // JPEG
            || str_starts_with($head, "\x89PNG\r\n\x1A\n")            // PNG
            || str_starts_with($head, 'GIF87a')                        // GIF
            || str_starts_with($head, 'GIF89a')
            || (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP')
            || str_starts_with($head, 'BM')                            // BMP
            || (substr($head, 4, 4) === 'ftyp'                         // AVIF / HEIC
                && in_array(substr($head, 8, 4), ['avif', 'avis', 'heic', 'heix', 'mif1'], true));
    }

    public static function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB', 'PB'];
        $value = $bytes / 1024;
        $index = 0;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return number_format($value, $value >= 100 ? 0 : 1) . ' ' . $units[$index];
    }

    public static function directorySize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $total = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                $total += $entry->getSize();
            }
        }

        return $total;
    }
}
