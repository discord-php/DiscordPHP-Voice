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

namespace Discord\Tests\Unit\Voice;

use Discord\Voice\ByteBuffer\Buffer;

it('extract succeeds when offset + length equals buffer size exactly', function (): void {
    $buf = makeEightByteBuffer();

    // read(0, 8) spans the entire 8-byte buffer — must not throw
    expect($buf->read(0, 8))->toBeString()->toHaveLength(8);
});

it('extract throws OutOfRangeException when offset + length exceeds buffer size by one', function (): void {
    $buf = makeEightByteBuffer();

    $buf->read(0, 9);
})->throws(\OutOfRangeException::class);

it('extract throws OutOfRangeException for negative offset', function (): void {
    $buf = makeEightByteBuffer();

    $buf->read(-1, 1);
})->throws(\OutOfRangeException::class);

it('extract throws OutOfRangeException for negative length', function (): void {
    $buf = makeEightByteBuffer();

    $buf->read(0, -1);
})->throws(\OutOfRangeException::class);

it('readInt16BE succeeds at a valid position', function (): void {
    // Pack two big-endian uint16 values (0x0102, 0x0304) into 4 bytes
    $buf = new Buffer(pack('nn', 0x0102, 0x0304));

    expect($buf->readInt16BE(0))->toBe(0x0102)
        ->and($buf->readInt16BE(2))->toBe(0x0304);
});

it('readInt32BE throws OutOfRangeException at an invalid position', function (): void {
    // 4-byte buffer; reading 4 bytes from offset 1 would require bytes 1–4 (index 5 is OOB)
    $buf = new Buffer(pack('N', 0xDEADBEEF));

    $buf->readInt32BE(1);
})->throws(\OutOfRangeException::class);

it('OutOfRangeException message contains offset, length and buffer size substrings', function (): void {
    $buf = makeEightByteBuffer();

    try {
        $buf->read(5, 6); // 5 + 6 = 11 > 8
        expect(false)->toBeTrue('Expected OutOfRangeException was not thrown');
    } catch (\OutOfRangeException $e) {
        expect($e->getMessage())
            ->toContain('offset=')
            ->toContain('length=')
            ->toContain('buffer size=');
    }
});

// Helpers

function makeEightByteBuffer(): Buffer
{
    return new Buffer(pack('CCCCCCCC', 1, 2, 3, 4, 5, 6, 7, 8));
}
