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
use Discord\Voice\ByteBuffer\FormatPackEnum;

it('constructs from string and round-trips bytes via __toString', function (): void {
    $buf = new Buffer('abcd');

    expect((string) $buf)->toBe('abcd')
        ->and($buf->length())->toBe(4);
});

it('constructs from int (zero-fills) and length matches', function (): void {
    $buf = new Buffer(8);

    expect($buf->length())->toBe(8)
        ->and(strlen((string) $buf))->toBe(8);
});

it('rejects negative size in constructor', function (): void {
    expect(fn (): Buffer => new Buffer(-1))->toThrow(\OutOfRangeException::class);
});

it('rejects non-int / non-string constructor argument', function (): void {
    expect(fn (): Buffer => new Buffer(['array']))->toThrow(\InvalidArgumentException::class);
});

it('exposes static make() factory', function (): void {
    $buf = Buffer::make('xyz');

    expect($buf)->toBeInstanceOf(Buffer::class)
        ->and((string) $buf)->toBe('xyz');
});

it('round-trips writeInt8/readInt8 across byte range', function (): void {
    $buf = new Buffer(2);
    $buf->writeInt8(0x7f, 0);
    $buf->writeInt8(0xff, 1);

    expect($buf->readInt8(0))->toBe(0x7f)
        ->and($buf->readInt8(1))->toBe(0xff);
});

it('rejects oversize writeInt8 input', function (): void {
    $buf = new Buffer(1);

    expect(fn () => $buf->writeInt8(0x100, 0))->toThrow(\InvalidArgumentException::class);
});

it('rejects negative value on writeInt8', function (): void {
    $buf = new Buffer(1);

    expect(fn () => $buf->writeInt8(-1, 0))->toThrow(\OutOfRangeException::class);
});

it('round-trips writeInt16BE / readInt16BE', function (): void {
    $buf = new Buffer(2);
    $buf->writeInt16BE(0x1234, 0);

    expect($buf->readInt16BE(0))->toBe(0x1234)
        ->and(bin2hex((string) $buf))->toBe('1234');
});

it('round-trips writeInt16LE / readInt16LE', function (): void {
    $buf = new Buffer(2);
    $buf->writeInt16LE(0xABCD, 0);

    expect($buf->readInt16LE(0))->toBe(0xABCD)
        ->and(bin2hex((string) $buf))->toBe('cdab');
});

it('round-trips writeInt32BE / readInt32BE', function (): void {
    $buf = new Buffer(4);
    $buf->writeInt32BE(0xDEADBEEF, 0);

    expect($buf->readInt32BE(0))->toBe(0xDEADBEEF);
});

it('round-trips writeInt32LE / readInt32LE', function (): void {
    $buf = new Buffer(4);
    $buf->writeInt32LE(0xCAFEBABE, 0);

    expect($buf->readInt32LE(0))->toBe(0xCAFEBABE);
});

it('write(string) appends at the first empty position when offset omitted', function (): void {
    $buf = new Buffer(6);
    $buf->write('ab');
    $buf->write('cd');

    expect((string) $buf)->toStartWith('abcd');
});

it('extract throws OutOfRange on negative offset', function (): void {
    $buf = new Buffer(4);

    expect(fn () => $buf->readInt8(-1))->toThrow(\OutOfRangeException::class);
});

it('extract throws when offset+length exceeds size', function (): void {
    $buf = new Buffer(2);

    expect(fn () => $buf->readInt32BE(0))->toThrow(\OutOfRangeException::class);
});

// trait coverage

it('writeInt / readInt round-trip via trait helpers', function (): void {
    $buf = new Buffer(4);
    $buf->writeInt(0x11223344, 0);

    expect($buf->readInt(0))->toBe(0x11223344);
});

it('writeUInt / readUInt round-trip via trait helpers', function (): void {
    $buf = new Buffer(4);
    $buf->writeUInt(0xAABBCCDD, 0);

    expect($buf->readUInt(0))->toBe(0xAABBCCDD);
});

it('writeShort / readShort round-trip via trait helpers', function (): void {
    $buf = new Buffer(2);
    $buf->writeShort(0x55AA, 0);

    expect($buf->readShort(0))->toBe(0x55AA);
});

it('writeUInt64LE round-trips via SplFixedArray storage', function (): void {
    $buf = new Buffer(8);
    $buf->writeUInt64LE(0x1122334455667788, 0);

    expect(bin2hex((string) $buf))->toBe('8877665544332211');
});

it('writeRaw stores an integer byte value at the offset', function (): void {
    $buf = new Buffer(2);
    $buf->writeRaw(0x51, 0);

    expect($buf[0])->toBe(0x51);
});

it('writeRawString writes a string byte-by-byte', function (): void {
    $buf = new Buffer(4);
    $buf->writeRawString('test', 0);

    expect((string) $buf)->toBe('test');
});

it('offsetGet / offsetSet / offsetExists / offsetUnset behave like an array', function (): void {
    $buf = new Buffer('abc');

    expect(isset($buf[0]))->toBeTrue()
        ->and($buf[0])->toBe('a')
        ->and($buf[99] ?? null)->toBeNull();

    $buf[0] = 'x';
    expect($buf[0])->toBe('x');

    unset($buf[0]);
    expect(isset($buf[0]))->toBeFalse();
});

it('readUIntLE round-trips a 32-bit value packed little-endian', function (): void {
    $buf = new Buffer(pack('V', 0x12345678));

    expect($buf->readUIntLE(0))->toBe(0x12345678);
});

it('writeUInt32BE truncates to 3 bytes per its insert(length=3) contract', function (): void {
    $buf = new Buffer(4);
    $buf->writeUInt32BE(0xAABBCCDD, 0);

    // Trait writes only the low 3 bytes (length=3 in insert), high byte untouched.
    expect(bin2hex((string) $buf))->toMatch('/^00aabbcc$|^aabbcc.{2}$/');
});

it('writeInt8/16BE/16LE/32BE/32LE auto-resolve offset via getLastEmptyPosition', function (): void {
    $buf = new Buffer(13);
    $buf->writeInt8(0xAB);
    $buf->writeInt16BE(0x1234);
    $buf->writeInt16LE(0xABCD);
    $buf->writeInt32BE(0xDEADBEEF);
    $buf->writeInt32LE(0xCAFEBABE);

    expect(bin2hex((string) $buf))->toBe('ab1234cdabdeadbeefbebafeca');
});

it('write(string) without explicit offset writes after previous content', function (): void {
    $buf = new Buffer(6);
    $buf->write('AB');
    $buf->write('CD');

    expect((string) $buf)->toStartWith('ABCD');
});

it('getLastEmptyPosition returns 0 when buffer is fully populated', function (): void {
    $buf = new Buffer('full');

    $r = new \ReflectionMethod(Buffer::class, 'getLastEmptyPosition');
    $r->setAccessible(true);
    expect($r->invoke($buf))->toBe(0);
});

it('FormatPackEnum::getLength returns the correct byte length per format', function (): void {
    expect(FormatPackEnum::C->getLength())->toBe(1)
        ->and(FormatPackEnum::n->getLength())->toBe(2)
        ->and(FormatPackEnum::N->getLength())->toBe(4)
        ->and(FormatPackEnum::v->getLength())->toBe(2)
        ->and(FormatPackEnum::V->getLength())->toBe(4);
});
