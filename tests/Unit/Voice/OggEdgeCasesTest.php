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
use Discord\Voice\OggStream;
use Discord\Voice\OpusHead;
use Discord\Voice\OpusTags;

use function React\Async\await;

it('parses the BOS (begin-of-stream) flag in the page header type', function (): void {
    $buffer = new Buffer();
    $buffer->write(buildEdgeOggPage(segments: [4], segmentData: 'data', headerType: 0x02));

    $page = await(OggPage::fromBuffer($buffer));

    $headerType = readEdgePageProperty($page, 'headerType');
    expect($headerType)->toBe(0x02)
        ->and(($headerType & 0x02) !== 0)->toBeTrue();
});

it('parses the EOS (end-of-stream) flag in the page header type', function (): void {
    $buffer = new Buffer();
    $buffer->write(buildEdgeOggPage(segments: [4], segmentData: 'data', headerType: 0x04));

    $page = await(OggPage::fromBuffer($buffer));

    $headerType = readEdgePageProperty($page, 'headerType');
    expect($headerType)->toBe(0x04)
        ->and(($headerType & 0x04) !== 0)->toBeTrue();
});

it('parses the continued-packet flag in the page header type', function (): void {
    $buffer = new Buffer();
    $buffer->write(buildEdgeOggPage(segments: [4], segmentData: 'data', headerType: 0x01));

    $page = await(OggPage::fromBuffer($buffer));

    $headerType = readEdgePageProperty($page, 'headerType');
    expect($headerType)->toBe(0x01)
        ->and(($headerType & 0x01) !== 0)->toBeTrue();
});

it('yields one packet from a multi-segment page that ends with a non-255 lacing', function (): void {
    $segmentData = str_repeat('A', 255).str_repeat('A', 255).str_repeat('A', 100);
    $buffer = new Buffer();
    $buffer->write(buildEdgeOggPage(segments: [255, 255, 100], segmentData: $segmentData));

    $page = await(OggPage::fromBuffer($buffer));
    $packets = iterator_to_array($page->iterPackets(), false);

    expect($packets)->toHaveCount(1)
        ->and($packets[0])->toBe([$segmentData, true]);
});

it('marks a packet as continuing when a multi-segment page ends with a 255 lacing', function (): void {
    $segmentData = str_repeat('X', 255).str_repeat('X', 255);
    $buffer = new Buffer();
    $buffer->write(buildEdgeOggPage(segments: [255, 255], segmentData: $segmentData));

    $page = await(OggPage::fromBuffer($buffer));
    $packets = iterator_to_array($page->iterPackets(), false);

    expect($packets)->toHaveCount(1)
        ->and($packets[0][0])->toBe($segmentData)
        ->and($packets[0][1])->toBeFalse();
});

it('parses opus head channels, sample rate, and pre-skip', function (): void {
    $head = new OpusHead('OpusHead'.pack('CCvVvC', 1, 2, 312, 48000, 0, 0));

    expect($head->channelCount)->toBe(2)
        ->and($head->sampleRate)->toBe(48000)
        ->and($head->preSkip)->toBe(312);
});

it('parses opus tags vendor and comment list', function (): void {
    $vendor = 'libopus 1.3.1';
    $comments = ['ENCODER=opusenc', 'TITLE=Edge Case'];

    $data = 'OpusTags'.pack('V', strlen($vendor)).$vendor.pack('V', count($comments));
    foreach ($comments as $c) {
        $data .= pack('V', strlen($c)).$c;
    }

    $tags = new OpusTags($data);

    expect($tags->vendor)->toBe($vendor)
        ->and($tags->tags)->toBe($comments);
});

it('emits packets in order across multiple ogg pages via OggStream', function (): void {
    $headerData = 'OpusHead'.pack('CCvVvC', 1, 2, 312, 48000, 0, 0);
    $tagsData = 'OpusTags'.pack('V', 0).pack('V', 0);

    $packetA = 'PACKET-A';
    $packetB = 'PACKET-B';
    $packetC = 'PACKET-C';

    $buffer = new Buffer();
    $buffer->write(
        buildEdgeOggPage([strlen($headerData)], $headerData)
        .buildEdgeOggPage([strlen($tagsData)], $tagsData)
        .buildEdgeOggPage([strlen($packetA)], $packetA)
        .buildEdgeOggPage([strlen($packetB), strlen($packetC)], $packetB.$packetC)
    );

    $stream = await(OggStream::fromBuffer($buffer));

    expect(await($stream->getPacket()))->toBe($packetA)
        ->and(await($stream->getPacket()))->toBe($packetB)
        ->and(await($stream->getPacket()))->toBe($packetC);
});

it('signals EOS by resolving subsequent OggStream::getPacket() calls with null', function (): void {
    $headerData = 'OpusHead'.pack('CCvVvC', 1, 2, 312, 48000, 0, 0);
    $tagsData = 'OpusTags'.pack('V', 0).pack('V', 0);
    $finalPacket = 'LAST';

    $buffer = new Buffer();
    $buffer->write(
        buildEdgeOggPage([strlen($headerData)], $headerData)
        .buildEdgeOggPage([strlen($tagsData)], $tagsData)
        .buildEdgeOggPage([strlen($finalPacket)], $finalPacket, headerType: 0x04)
    );
    $buffer->close();

    $stream = await(OggStream::fromBuffer($buffer));

    expect(await($stream->getPacket()))->toBe($finalPacket)
        ->and(await($stream->getPacket()))->toBeNull()
        ->and(await($stream->getPacket()))->toBeNull();
});

// Helpers

function buildEdgeOggPage(
    array $segments,
    string $segmentData,
    int $headerType = 0,
    int $granulePosition = 0,
    int $bitstreamSn = 1,
    int $pageSeq = 1,
): string {
    $page = 'OggS'
        .pack('CCPVVVC', 0, $headerType, $granulePosition, $bitstreamSn, $pageSeq, 0, count($segments))
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

function readEdgePageProperty(OggPage $page, string $property): mixed
{
    $reflection = new \ReflectionProperty($page, $property);
    $reflection->setAccessible(true);

    return $reflection->getValue($page);
}
