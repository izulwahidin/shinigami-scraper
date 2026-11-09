<?php

declare(strict_types=1);

namespace Shinigami\Support;

final class Slugger
{
    private const RESERVED = [
        'con', 'prn', 'aux', 'nul',
        'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9',
        'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9',
    ];

    public static function slug(string $value, int $maxLength = 90): string
    {
        $value = trim($value);

        if ($value === '') {
            return 'untitled';
        }

        if (class_exists(\Transliterator::class)) {
            $transliterator = \Transliterator::create('Any-Latin; Latin-ASCII');
            if ($transliterator !== null) {
                $value = $transliterator->transliterate($value);
            }
        } else {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }

        $value = mb_strtolower($value, 'UTF-8');
        $value = str_replace(["'", '’', '`'], '', $value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        $value = preg_replace('/-{2,}/', '-', $value) ?? '';
        $value = substr($value, 0, $maxLength);
        $value = rtrim($value, '-.');

        if ($value === '') {
            return 'untitled';
        }

        if (in_array($value, self::RESERVED, true)) {
            $value = 'manga-' . $value;
        }

        return $value;
    }

    public static function shortId(string $id, int $length = 8): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9]/', '', $id) ?? '';

        return strtolower(substr($clean, 0, $length)) ?: 'unknown';
    }
}
