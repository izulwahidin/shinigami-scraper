<?php

declare(strict_types=1);

namespace Shinigami\Domain;

final class Project
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $mangaId,
        public readonly string $title,
        public readonly string $alternativeTitle,
        public readonly string $format,
        public readonly ?string $coverImageUrl,
        public readonly ?string $coverPortraitUrl,
        public readonly ?string $latestChapterId,
        public readonly float $latestChapterNumber,
        public readonly ?string $sourceUpdatedAt,
        public readonly array $payload,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromApi(array $data): self
    {
        $formats = $data['taxonomy']['Format'] ?? [];
        $format = 'manga';

        if (is_array($formats) && isset($formats[0]['slug']) && is_string($formats[0]['slug'])) {
            $format = strtolower($formats[0]['slug']);
        }

        if (!in_array($format, ['manga', 'manhwa', 'manhua'], true)) {
            $format = 'manga';
        }

        return new self(
            mangaId: (string) ($data['manga_id'] ?? ''),
            title: trim((string) ($data['title'] ?? 'Untitled')),
            alternativeTitle: trim((string) ($data['alternative_title'] ?? '')),
            format: $format,
            coverImageUrl: self::nullableString($data['cover_image_url'] ?? null),
            coverPortraitUrl: self::nullableString($data['cover_portrait_url'] ?? null),
            latestChapterId: self::nullableString($data['latest_chapter_id'] ?? null),
            latestChapterNumber: (float) ($data['latest_chapter_number'] ?? 0),
            sourceUpdatedAt: self::nullableString($data['updated_at'] ?? null),
            payload: $data,
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
