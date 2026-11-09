<?php

declare(strict_types=1);

/**
 * Dependency-free smoke tests for the pure/helper parts of the scraper.
 * Run with: php tests/run.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use Shinigami\Console\Input;
use Shinigami\Domain\ChapterDetail;
use Shinigami\Support\FileSystem;
use Shinigami\Support\PathBuilder;
use Shinigami\Support\Slugger;

$tests = 0;
$failures = 0;
$check = static function (string $name, bool $condition, string $detail = '') use (&$tests, &$failures): void {
    $tests++;

    if ($condition) {
        fwrite(STDOUT, 'PASS  ' . $name . PHP_EOL);

        return;
    }

    $failures++;
    fwrite(STDERR, 'FAIL  ' . $name . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL);
};

// ---------------------------------------------------------------- Slugger
$check('slug: basic', Slugger::slug('The Player Hides His Past') === 'the-player-hides-his-past');
$check('slug: punctuation', Slugger::slug('A  --  B!!') === 'a-b');
$check('slug: apostrophe', Slugger::slug("The Prodigy's Return") === 'the-prodigys-return');
$check('slug: empty', Slugger::slug('   ') === 'untitled');
$check('slug: reserved windows name', Slugger::slug('CON') === 'manga-con');
$check('slug: non latin', preg_match('/^[a-z0-9-]+$/', Slugger::slug('플레이어가 과거를 숨김')) === 1);
$check('short id', Slugger::shortId('f9b2bafd-64d6-4eb7-86df-0cc9684ffc56') === 'f9b2bafd');

// ------------------------------------------------------------ PathBuilder
$check('chapter folder: integer', PathBuilder::chapterFolderName(146.0) === 'chapter-0146');
$check('chapter folder: zero', PathBuilder::chapterFolderName(0.0) === 'chapter-0000');
$check('chapter folder: decimal', PathBuilder::chapterFolderName(12.5) === 'chapter-0012.5');
$check('chapter folder: sub-one', PathBuilder::chapterFolderName(0.5) === 'chapter-0000.5');

$paths = new PathBuilder('/tmp/shinigami-test-downloads');
$check(
    'project dir',
    $paths->projectDir('manhwa', 'the-player') === '/tmp/shinigami-test-downloads/manhwa/the-player'
);
$check(
    'chapter image path',
    $paths->chapterImagePath('manhwa', 'the-player', 'chapter-0146', 1, 3, 'jpg')
        === '/tmp/shinigami-test-downloads/manhwa/the-player/chapter-0146/001.jpg'
);

// ---------------------------------------------------------- ChapterDetail
$detail = ChapterDetail::fromApi([
    'chapter_id' => 'c1',
    'manga_id' => 'm1',
    'chapter_number' => 12.5,
    'base_url' => 'https://assets.example/',
    'base_url_low' => 'https://low.example',
    'chapter' => [
        'path' => '/chapter/manga_m1/chapter_c1/',
        'data' => ['00-a.jpg', '01-b.jpg', '', null],
    ],
    'updated_at' => '2026-01-01T00:00:00Z',
]);

$check('detail: image count filters empty entries', $detail->imageCount() === 2);
$check(
    'detail: image url',
    $detail->imageUrl('00-a.jpg') === 'https://assets.example/chapter/manga_m1/chapter_c1/00-a.jpg'
);
$check(
    'detail: image url encodes unsafe names',
    $detail->imageUrl('00 a.jpg') === 'https://assets.example/chapter/manga_m1/chapter_c1/00%20a.jpg'
);

$fallback = ChapterDetail::fromApi([
    'base_url' => null,
    'base_url_low' => 'https://low.example',
    'chapter' => ['path' => '/p/', 'data' => ['x.jpg']],
]);
$check('detail: base_url fallback', $fallback->imageUrl('x.jpg') === 'https://low.example/p/x.jpg');

// --------------------------------------------------------------- Input
$input = Input::parse([
    'sync',
    '--project=abc',
    '--latest',
    '5',
    '--force',
    '-vv',
    '--type=manhwa',
    'positional',
]);
$check('input: command', $input->command() === 'sync');
$check('input: option with equals', $input->option('project') === 'abc');
$check('input: option with space', $input->intOption('latest') === 5);
$check('input: flag', $input->boolOption('force') === true);
$check('input: verbosity', $input->verbosity() === 2);
$check('input: positional argument', $input->argument(0) === 'positional');

$quiet = Input::parse(['stats', '-q']);
$check('input: quiet', $quiet->isQuiet() === true);

// ----------------------------------------------------------- FileSystem
$tmpJpeg = sys_get_temp_dir() . '/shinigami-test-' . getmypid() . '.jpg';
$tmpHtml = sys_get_temp_dir() . '/shinigami-test-' . getmypid() . '.html';
file_put_contents($tmpJpeg, "\xFF\xD8\xFF\xE0" . str_repeat('x', 64));
file_put_contents($tmpHtml, '<html><body>blocked</body></html>');
$check('filesystem: jpeg magic accepted', FileSystem::isImage($tmpJpeg) === true);
$check('filesystem: html rejected', FileSystem::isImage($tmpHtml) === false);
@unlink($tmpJpeg);
@unlink($tmpHtml);

$check('filesystem: human bytes', FileSystem::humanBytes(1536) === '1.5 KB');

fwrite(STDOUT, PHP_EOL . sprintf('Tests: %d, failures: %d', $tests, $failures) . PHP_EOL);

exit($failures === 0 ? 0 : 1);
