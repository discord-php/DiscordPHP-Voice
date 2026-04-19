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
        checksum: 0x11223344
    ));

    $page = await(OggPage::fromBuffer($buffer));

    expect(readOggPageProperty($page, 'version'))->toBe(0)
        ->and(readOggPageProperty($page, 'headerType'))->toBe(2)
        ->and(readOggPageProperty($page, 'granulePosition'))->toBe(123456789)
        ->and(readOggPageProperty($page, 'bitstreamSn'))->toBe(456)
        ->and(readOggPageProperty($page, 'pageSeq'))->toBe(7)
        ->and(readOggPageProperty($page, 'checksum'))->toBe(0x11223344)
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

function buildOggPageFixture(
    array $segments,
    string $segmentData,
    int $version = 0,
    int $headerType = 0,
    int $granulePosition = 0,
    int $bitstreamSn = 1,
    int $pageSeq = 1,
    int $checksum = 0
): string {
    return 'OggS'
        .pack('CCPVVVC', $version, $headerType, $granulePosition, $bitstreamSn, $pageSeq, $checksum, count($segments))
        .pack('C*', ...$segments)
        .$segmentData;
}

function readOggPageProperty(OggPage $page, string $property): mixed
{
    $reflection = new \ReflectionProperty($page, $property);
    $reflection->setAccessible(true);

    return $reflection->getValue($page);
}
