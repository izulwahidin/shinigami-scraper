<?php

declare(strict_types=1);

namespace Shinigami\Domain;

final class ChapterSummary
{
    public function __construct(
        public readonly string $chapterId,
        public readonly string $mangaId,
        public readonly float $chapterNumber,
        public readonly string $title,
        public readonly ?string $releaseDate,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromApi(array $data): self
    {
        return new self(
            chapterId: (string) ($data['chapter_id'] ?? ''),
            mangaId: (string) ($data['manga_id'] ?? ''),
            chapterNumber: (float) ($data['chapter_number'] ?? 0),
            title: trim((string) ($data['chapter_title'] ?? '')),
            releaseDate: self::nullableString($data['release_date'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
