<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP project.
 *
 * Copyright (c) 2015-2022 David Cole <david.cole1340@gmail.com>
 * Copyright (c) 2020-present Valithor Obsidion <valithor@discordphp.org>
 * Copyright (c) 2025-present Alexandre Candeias (Sky) <sky@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Discord\Tests\Unit\Voice\Recording;

use Discord\Voice\Recording\WavWriter;

// ---------------------------------------------------------------------------
// 1. open() creates a 44-byte file with RIFF/WAVE/fmt  markers
// ---------------------------------------------------------------------------

it('open() creates a 44-byte file with RIFF header', function (): void {
    $path = wavWriterTempPath('open-header');
    $writer = new WavWriter($path);
    $writer->open();

    expect(filesize($path))->toBe(44);

    $raw = file_get_contents($path);
    expect(substr($raw, 0, 4))->toBe('RIFF')
        ->and(substr($raw, 8, 4))->toBe('WAVE')
        ->and(substr($raw, 12, 4))->toBe('fmt ');

    unlink($path);
});

// ---------------------------------------------------------------------------
// 2. write() appends PCM bytes and grows the file
// ---------------------------------------------------------------------------

it('write() appends PCM bytes and grows the file', function (): void {
    $path = wavWriterTempPath('write-grows');
    $writer = new WavWriter($path);
    $writer->open();
    $writer->write(str_repeat("\x00", 100));

    expect(filesize($path))->toBe(144); // 44 header + 100 PCM

    unlink($path);
});

// ---------------------------------------------------------------------------
// 3. finalize() rewrites header with correct sizes
// ---------------------------------------------------------------------------

it('finalize() rewrites header with correct sizes', function (): void {
    $path = wavWriterTempPath('finalize-sizes');
    $pcm = str_repeat("\xAB\xCD", 960); // 1920 bytes

    $writer = new WavWriter($path);
    $writer->open();
    $writer->write($pcm);
    $writer->finalize();

    $raw = file_get_contents($path);
    expect(strlen($raw))->toBe(44 + 1920)
        ->and(substr($raw, 0, 4))->toBe('RIFF')
        ->and(substr($raw, 4, 4))->toBe(pack('V', 36 + 1920))  // chunkSize = 1956
        ->and(substr($raw, 36, 4))->toBe('data')
        ->and(substr($raw, 40, 4))->toBe(pack('V', 1920));      // dataBytes

    unlink($path);
});

// ---------------------------------------------------------------------------
// 4. finalize() closes the handle — subsequent write() throws RuntimeException
// ---------------------------------------------------------------------------

it('finalize() closes the handle — subsequent write() throws RuntimeException', function (): void {
    $path = wavWriterTempPath('finalize-closes');
    $writer = new WavWriter($path);
    $writer->open();
    $writer->finalize();

    expect(fn () => $writer->write('x'))->toThrow(\RuntimeException::class);

    unlink($path);
});

// ---------------------------------------------------------------------------
// 5. write() before open() throws RuntimeException
// ---------------------------------------------------------------------------

it('write() before open() throws RuntimeException', function (): void {
    $writer = new WavWriter(wavWriterTempPath('write-before-open'));

    expect(fn () => $writer->write('x'))->toThrow(\RuntimeException::class);
});

// ---------------------------------------------------------------------------
// 6. finalize() before open() throws RuntimeException
// ---------------------------------------------------------------------------

it('finalize() before open() throws RuntimeException', function (): void {
    $writer = new WavWriter(wavWriterTempPath('finalize-before-open'));

    expect(fn () => $writer->finalize())->toThrow(\RuntimeException::class);
});

// ---------------------------------------------------------------------------
// 7. getPath() returns the constructor path
// ---------------------------------------------------------------------------

it('getPath() returns the constructor path', function (): void {
    $path = '/some/path/audio.wav';
    $writer = new WavWriter($path);

    expect($writer->getPath())->toBe($path);
});

// ---------------------------------------------------------------------------
// 8. open() throws RuntimeException when path is not writable
// ---------------------------------------------------------------------------

if (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Windows') {
    it('open() throws RuntimeException when path is not writable', function (): void {
    })->skip('Path semantics differ on Windows; skipping this Unix-specific assertion.');
} else {
    withoutErrorHandler(it('open() throws RuntimeException when path is not writable', function (): void {
        $writer = new WavWriter('/nonexistent/path/file.wav');

        expect(fn () => @$writer->open())->toThrow(\RuntimeException::class);
    }));
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function wavWriterTempPath(string $tag): string
{
    return sys_get_temp_dir().'/wavwriter-test-'.$tag.'-'.getmypid().'.wav';
}
