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

use Discord\Voice\Client\RtpHeader;

it('headerSize returns 12 for packet with 0 CSRC fields', function (): void {
    $packet = pack('CCnNN', 0x80, 0x78, 1, 2, 3).'payload';

    expect(RtpHeader::headerSize($packet))->toBe(12);
});

it('headerSize returns 16 for packet with 1 CSRC field', function (): void {
    $packet = pack('CCnNNN', 0x81, 0x78, 1, 2, 3, 4).'payload';

    expect(RtpHeader::headerSize($packet))->toBe(16);
});

it('headerSize returns 20 for packet with 2 CSRC fields', function (): void {
    $packet = pack('CCnNNNN', 0x82, 0x78, 1, 2, 3, 4, 5).'payload';

    expect(RtpHeader::headerSize($packet))->toBe(20);
});

it('hasExtension returns false when X bit is clear', function (): void {
    $packet = pack('CCnNN', 0x80, 0x78, 1, 2, 3).'payload';

    expect(RtpHeader::hasExtension($packet))->toBeFalse();
});

it('hasExtension returns true when X bit is set', function (): void {
    $packet = pack('CCnNN', 0x90, 0x78, 1, 2, 3).'payload';

    expect(RtpHeader::hasExtension($packet))->toBeTrue();
});

it('stripExtensionPayload returns audio unchanged when no extension', function (): void {
    $packet = pack('CCnNN', 0x80, 0x78, 1, 2, 3).'opus-audio';

    expect(RtpHeader::stripExtensionPayload($packet, RtpHeader::headerSize($packet)))->toBe($packet);
});

it('stripExtensionPayload strips extension correctly', function (): void {
    $baseHeader = pack('CCnNN', 0x90, 0x78, 1, 2, 3);   // 12 bytes, X bit set, 0 CSRC
    $extHeader = "\xBE\xDE".pack('n', 1);              // profile + 1 word (4 bytes) of extension data
    $extData = 'EXT!';                                   // 4 bytes = 1 word
    $audio = 'opus-audio';
    $packet = $baseHeader.$extHeader.$extData.$audio;

    expect(RtpHeader::stripExtensionPayload($packet, RtpHeader::headerSize($packet)))->toBe($baseHeader.$audio);
});
