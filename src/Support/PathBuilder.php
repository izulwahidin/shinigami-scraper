<?php

declare(strict_types=1);

namespace Shinigami\Support;

use RuntimeException;

/**
 * Single source of truth for every on-disk path.
 *
 * Layout:
 *   downloads/<format>/<project-slug>/
 *       project.json
 *       cover.jpg
 *       cover_portrait.jpg
 *       chapter-0001/
 *           metadata.json
 *           001.jpg
 *           002.jpg
 */
final class PathBuilder
{
    private readonly string $base;

    public function __construct(string $downloadDir)
    {
        $this->base = rtrim($downloadDir, '/\\');
    }

    public function base(): string
    {
        return $this->base;
    }

    public function projectDir(string $format, string $slug): string
    {
        return $this->base . '/' . $this->safeFormat($format) . '/' . $this->safeComponent($slug);
    }

    public function chapterDir(string $format, string $slug, string $folder): string
    {
        return $this->projectDir($format, $slug) . '/' . $this->safeComponent($folder);
    }

    public function chapterImagePath(
        string $format,
        string $slug,
        string $folder,
        int $index,
        int $width,
        string $extension,
    ): string {
        $extension = $this->safeExtension($extension);
        $name = str_pad((string) $index, $width, '0', STR_PAD_LEFT) . '.' . $extension;

        return $this->chapterDir($format, $slug, $folder) . '/' . $name;
    }

    public function coverPath(string $format, string $slug, bool $portrait = false): string
    {
        return $this->projectDir($format, $slug) . ($portrait ? '/cover_portrait.jpg' : '/cover.jpg');
    }

    public function projectMetadataPath(string $format, string $slug): string
    {
        return $this->projectDir($format, $slug) . '/project.json';
    }

    public function chapterMetadataPath(string $format, string $slug, string $folder): string
    {
        return $this->chapterDir($format, $slug, $folder) . '/metadata.json';
    }

    public function ensureChapterDir(string $format, string $slug, string $folder): string
    {
        $dir = $this->chapterDir($format, $slug, $folder);
        FileSystem::ensureDir($dir);

        return $dir;
    }

    public function deleteChapterDir(string $format, string $slug, string $folder): void
    {
        $dir = $this->chapterDir($format, $slug, $folder);
        $this->assertInsideBase($dir);
        FileSystem::deleteDirectory($dir);
    }

    public function relative(string $path): string
    {
        $prefix = $this->base . '/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    public static function chapterFolderName(float $number): string
    {
        $number = max(0.0, $number);

        if (abs($number - round($number)) < 0.0001) {
            return 'chapter-' . str_pad((string) (int) round($number), 4, '0', STR_PAD_LEFT);
        }

        $formatted = rtrim(rtrim(number_format($number, 3, '.', ''), '0'), '.');
        [$whole, $decimal] = array_pad(explode('.', $formatted, 2), 2, '0');

        return 'chapter-' . str_pad($whole, 4, '0', STR_PAD_LEFT) . '.' . $decimal;
    }

    private function safeFormat(string $format): string
    {
        $format = strtolower(trim($format));

        return in_array($format, ['manga', 'manhwa', 'manhua'], true) ? $format : 'manga';
    }

    private function safeComponent(string $value): string
    {
        if ($value === '' || str_contains($value, '/') || str_contains($value, '\\') || str_contains($value, '..')) {
            throw new RuntimeException(sprintf('Unsafe path component: %s', $value));
        }

        return $value;
    }

    private function safeExtension(string $extension): string
    {
        $extension = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $extension) ?? '');

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'bmp'], true)
            ? $extension
            : 'jpg';
    }

    private function assertInsideBase(string $path): void
    {
        if (!str_starts_with($path . '/', $this->base . '/')) {
            throw new RuntimeException(sprintf('Refusing to touch path outside download directory: %s', $path));
        }
    }
}
