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

use Discord\Voice\Helpers\Buffer;
use Discord\Voice\OggPage;
use UnexpectedValueException;

use function React\Async\await;

it('parses ogg pages from a buffer', function (): void {
    $segmentData = 'hellobye';
    $buffer = new Buffer();
    $buffer->write(buildOggPageFixture(
        segments: [5, 3],
        segmentData: $segmentData,
        headerType: 2,
        granulePosition: 123456789,
        bitstreamSn: 456,
        pageSeq: 7,
    ));

    $page = await(OggPage::fromBuffer($buffer));

    expect(readOggPageProperty($page, 'version'))->toBe(0)
        ->and(readOggPageProperty($page, 'headerType'))->toBe(2)
        ->and(readOggPageProperty($page, 'granulePosition'))->toBe(123456789)
        ->and(readOggPageProperty($page, 'bitstreamSn'))->toBe(456)
        ->and(readOggPageProperty($page, 'pageSeq'))->toBe(7)
        ->and(readOggPageProperty($page, 'checksum'))->toBe(0x68553ef8)
        ->and(readOggPageProperty($page, 'pageSegments'))->toBe([1 => 5, 2 => 3])
        ->and($page->segmentData)->toBe($segmentData)
        ->and(iterator_to_array($page->iterPackets(), false))->toBe([
            ['hello', true],
            ['bye', true],
        ]);
});

it('tracks complete and partial packets when iterating page segments', function (): void {
    $completePacket = str_repeat('A', 260);
    $partialPacket = str_repeat('B', 255);

    $buffer = new Buffer();
    $buffer->write(buildOggPageFixture(
        segments: [255, 5, 255],
        segmentData: $completePacket.$partialPacket
    ));

    $page = await(OggPage::fromBuffer($buffer));

    expect(iterator_to_array($page->iterPackets(), false))->toBe([
        [$completePacket, true],
        [$partialPacket, false],
    ]);
});

it('rejects invalid ogg page magic headers', function (): void {
    $buffer = new Buffer();
    $buffer->write('Bad!');

    expect(fn () => await(OggPage::fromBuffer($buffer)))
        ->toThrow(UnexpectedValueException::class, 'Invalid Ogg page header, expected OggS got Bad!.');
});

it('rejects ogg pages with a mismatched CRC checksum', function (): void {
    $pageBytes = buildOggPageFixture(segments: [4], segmentData: 'opus');
    // Corrupt the CRC field at bytes 22–25
    $corrupted = substr($pageBytes, 0, 22)."\xff\xff\xff\xff".substr($pageBytes, 26);
    $buffer = new Buffer();
    $buffer->write($corrupted);

    expect(fn () => await(OggPage::fromBuffer($buffer)))
        ->toThrow(UnexpectedValueException::class, 'CRC mismatch');
});

it('accepts ogg pages with a correct CRC checksum', function (): void {
    $buffer = new Buffer();
    $buffer->write(buildOggPageFixture(segments: [4], segmentData: 'opus', bitstreamSn: 99, pageSeq: 1));

    $page = await(OggPage::fromBuffer($buffer));

    expect($page)->toBeInstanceOf(OggPage::class)
        ->and($page->segmentData)->toBe('opus');
});

function buildOggPageFixture(
    array $segments,
    string $segmentData,
    int $version = 0,
    int $headerType = 0,
    int $granulePosition = 0,
    int $bitstreamSn = 1,
    int $pageSeq = 1,
): string {
    // Build with zeroed checksum, compute the correct Ogg CRC, then embed it.
    $page = 'OggS'
        .pack('CCPVVVC', $version, $headerType, $granulePosition, $bitstreamSn, $pageSeq, 0, count($segments))
        .pack('C*', ...$segments)
        .$segmentData;

    $crc = 0;
    for ($i = 0; $i < strlen($page); $i++) {
        $crc ^= (ord($page[$i]) << 24);
        for ($j = 0; $j < 8; $j++) {
            if ($crc & 0x80000000) {
                $crc = (($crc << 1) ^ 0x04C11DB7) & 0xFFFFFFFF;
            } else {
                $crc = ($crc << 1) & 0xFFFFFFFF;
            }
        }
    }

    return substr($page, 0, 22).pack('V', $crc).substr($page, 26);
}

function readOggPageProperty(OggPage $page, string $property): mixed
{
    $reflection = new \ReflectionProperty($page, $property);
    $reflection->setAccessible(true);

    return $reflection->getValue($page);
}
