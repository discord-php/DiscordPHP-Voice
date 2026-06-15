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
use Discord\Voice\OggStream;
use UnexpectedValueException;

use function React\Async\await;

it('reads opus metadata pages from a buffer', function (): void {
    $headerData = buildOggStreamHeaderFixture(channelCount: 2);
    $tagsData = buildOggStreamTagsFixture('discord-php', ['TITLE=Ready']);

    $buffer = new Buffer();
    $buffer->write(
        buildOggStreamPageFixture([strlen($headerData)], $headerData)
        .buildOggStreamPageFixture([strlen($tagsData)], $tagsData)
    );

    $stream = await(OggStream::fromBuffer($buffer));

    expect($stream->header->channelCount)->toBe(2)
        ->and($stream->header->channelMapFamily)->toBe(0)
        ->and($stream->tags->vendor)->toBe('discord-php')
        ->and($stream->tags->tags)->toBe(['TITLE=Ready']);
});

it('combines partial packets across pages and returns eof once exhausted', function (): void {
    $headerData = buildOggStreamHeaderFixture();
    $tagsData = buildOggStreamTagsFixture('discord-php');
    $packetHead = str_repeat('A', 255);
    $packetTail = str_repeat('B', 10);
    $secondPacket = 'END';

    $buffer = new Buffer();
    $buffer->write(
        buildOggStreamPageFixture([strlen($headerData)], $headerData)
        .buildOggStreamPageFixture([strlen($tagsData)], $tagsData)
        .buildOggStreamPageFixture([255], $packetHead)
        .buildOggStreamPageFixture([10, strlen($secondPacket)], $packetTail.$secondPacket)
    );
    $buffer->close();

    $stream = await(OggStream::fromBuffer($buffer));

    expect(await($stream->getPacket()))->toBe($packetHead.$packetTail)
        ->and(await($stream->getPacket()))->toBe($secondPacket)
        ->and(await($stream->getPacket()))->toBeNull()
        ->and(await($stream->getPacket()))->toBeNull();
});

it('returns eof when only an incomplete packet remains', function (): void {
    $headerData = buildOggStreamHeaderFixture();
    $tagsData = buildOggStreamTagsFixture('discord-php');
    $partialPacket = str_repeat('A', 255);

    $buffer = new Buffer();
    $buffer->write(
        buildOggStreamPageFixture([strlen($headerData)], $headerData)
        .buildOggStreamPageFixture([strlen($tagsData)], $tagsData)
        .buildOggStreamPageFixture([255], $partialPacket)
    );
    $buffer->close();

    $stream = await(OggStream::fromBuffer($buffer));

    expect(await($stream->getPacket()))->toBeNull()
        ->and(await($stream->getPacket()))->toBeNull();
});

it('surfaces invalid opus tag pages when opening a stream', function (): void {
    $headerData = buildOggStreamHeaderFixture();
    $invalidTags = 'BadMagic';

    $buffer = new Buffer();
    $buffer->write(
        buildOggStreamPageFixture([strlen($headerData)], $headerData)
        .buildOggStreamPageFixture([strlen($invalidTags)], $invalidTags)
    );

    expect(fn () => await(OggStream::fromBuffer($buffer)))
        ->toThrow(UnexpectedValueException::class, 'Expected OpusTags, found '.bin2hex('BadMagic'));
});

function buildOggStreamPageFixture(array $segments, string $segmentData): string
{
    // Build with zeroed checksum, compute the correct Ogg CRC, then embed it.
    $page = 'OggS'
        .pack('CCPVVVC', 0, 0, 0, 1, 1, 0, count($segments))
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

function buildOggStreamHeaderFixture(int $channelCount = 2): string
{
    return 'OpusHead'.pack('CCvVvC', 1, $channelCount, 312, 48000, 0, 0);
}

function buildOggStreamTagsFixture(string $vendor, array $tags = []): string
{
    $data = 'OpusTags'.pack('V', strlen($vendor)).$vendor.pack('V', count($tags));

    foreach ($tags as $tag) {
        $data .= pack('V', strlen($tag)).$tag;
    }

    return $data;
}
