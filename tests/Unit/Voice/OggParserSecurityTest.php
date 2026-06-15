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

use Discord\Voice\OpusHead;
use Discord\Voice\OpusTags;

// OpusTags tests

it('rejects OpusTags with wrong magic bytes', function () {
    $data = 'NotValid'.pack('V', 0);
    new OpusTags($data);
})->throws(\UnexpectedValueException::class);

it('rejects OpusTags with vendor_len exceeding data (HIGH-1)', function () {
    // Magic + vendor_len=9999 but only 4 more bytes of data
    $data = 'OpusTags'.pack('V', 9999).'abcd';
    new OpusTags($data);
})->throws(\UnexpectedValueException::class, 'vendor_len');

it('rejects OpusTags with num_tags exceeding max (HIGH-1)', function () {
    // Magic + vendor_len=0 + num_tags=0xFFFFFFFF
    $data = 'OpusTags'.pack('V', 0).pack('V', 0xFFFFFFFF);
    new OpusTags($data);
})->throws(\UnexpectedValueException::class, 'num_tags');

it('rejects OpusTags with tag_len exceeding remaining data (HIGH-1)', function () {
    // Magic + vendor_len=3 + vendor + num_tags=1 + tag_len=9999 + short data
    $data = 'OpusTags'.pack('V', 3).'abc'.pack('V', 1).pack('V', 9999).'xy';
    new OpusTags($data);
})->throws(\UnexpectedValueException::class, 'tag');

it('parses valid OpusTags correctly', function () {
    $vendor = 'TestVendor';
    $tag1 = 'TITLE=Test';
    $tag2 = 'ARTIST=Nobody';
    $data = 'OpusTags'
        .pack('V', strlen($vendor)).$vendor
        .pack('V', 2)
        .pack('V', strlen($tag1)).$tag1
        .pack('V', strlen($tag2)).$tag2;

    $tags = new OpusTags($data);
    expect($tags->vendor)->toBe('TestVendor')
        ->and($tags->tags)->toHaveCount(2)
        ->and($tags->tags[0])->toBe('TITLE=Test')
        ->and($tags->tags[1])->toBe('ARTIST=Nobody');
});

it('uses bin2hex in OpusTags error message instead of raw bytes (LOW-10)', function () {
    try {
        new OpusTags("\xFF\x00\x01\x02\x03\x04\x05\x06");
    } catch (\UnexpectedValueException $e) {
        // Should contain hex representation, not raw bytes
        expect($e->getMessage())->toContain('ff00010203040506');
    }
});

it('rejects OpusTags data too short for vendor length field', function () {
    $data = 'OpusTags'; // exactly 8 bytes, no vendor_len field
    new OpusTags($data);
})->throws(\UnexpectedValueException::class, 'too short');

// OpusHead tests

it('rejects OpusHead with short data for channel map (MED-10)', function () {
    // OpusHead format: 8 magic + version(1) + channels(1) + pre_skip(2) + sample_rate(4) + output_gain(2) + channel_map_family(1) = 19 bytes minimum
    // When channel_map_family > 0, needs at least 21 bytes for stream counts
    $data = 'OpusHead'
        .chr(1)     // version
        .chr(2)     // channel_count
        .pack('v', 0)  // pre_skip
        .pack('V', 48000) // sample_rate
        .pack('v', 0)  // output_gain
        .chr(1);    // channel_map_family = 1 (needs more data)
    // Only 19 bytes — not enough for stream_count fields at offset 19

    new OpusHead($data);
})->throws(\UnexpectedValueException::class, 'too short');

it('uses bin2hex in OpusHead error message (LOW-10)', function () {
    try {
        new OpusHead("\xFF\x00\x01\x02\x03\x04\x05\x06");
    } catch (\UnexpectedValueException $e) {
        expect($e->getMessage())->toContain('ff00010203040506');
    }
});

it('parses valid OpusHead without channel map', function () {
    $data = 'OpusHead'
        .chr(1)     // version
        .chr(2)     // channel_count
        .pack('v', 3840)  // pre_skip
        .pack('V', 48000) // sample_rate
        .pack('v', 0)  // output_gain
        .chr(0);    // channel_map_family = 0

    $head = new OpusHead($data);
    expect($head->version)->toBe(1)
        ->and($head->channelCount)->toBe(2)
        ->and($head->sampleRate)->toBe(48000)
        ->and($head->channelMapFamily)->toBe(0);
});
