<?php

declare(strict_types=1);

namespace Shinigami\Domain;

final class ChapterDetail
{
    /**
     * @param list<string> $images
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $chapterId,
        public readonly string $mangaId,
        public readonly float $chapterNumber,
        public readonly string $title,
        public readonly string $baseUrl,
        public readonly string $path,
        public readonly array $images,
        public readonly ?string $releaseDate,
        public readonly ?string $updatedAt,
        public readonly array $payload,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromApi(array $data): self
    {
        $chapter = is_array($data['chapter'] ?? null) ? $data['chapter'] : [];
        $images = array_values(array_filter(
            is_array($chapter['data'] ?? null) ? $chapter['data'] : [],
            static fn (mixed $file): bool => is_string($file) && $file !== ''
        ));

        $baseUrl = (string) ($data['base_url'] ?? $data['base_url_low'] ?? '');

        if ($baseUrl === '') {
            $baseUrl = (string) ($data['base_url_low'] ?? '');
        }

        return new self(
            chapterId: (string) ($data['chapter_id'] ?? ''),
            mangaId: (string) ($data['manga_id'] ?? ''),
            chapterNumber: (float) ($data['chapter_number'] ?? 0),
            title: trim((string) ($data['chapter_title'] ?? '')),
            baseUrl: $baseUrl,
            path: (string) ($chapter['path'] ?? ''),
            images: $images,
            releaseDate: self::nullableString($data['release_date'] ?? null),
            updatedAt: self::nullableString($data['updated_at'] ?? null),
            payload: $data,
        );
    }

    public function imageCount(): int
    {
        return count($this->images);
    }

    public function imageUrl(string $filename): string
    {
        $segments = array_map(
            static fn (string $segment): string => preg_match('/^[A-Za-z0-9._-]+$/', $segment) === 1
                ? $segment
                : rawurlencode($segment),
            explode('/', trim($filename, '/'))
        );

        return rtrim($this->baseUrl, '/')
            . '/'
            . trim($this->path, '/')
            . '/'
            . implode('/', $segments);
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
