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

// Classes the 8.2 restructuring moved: the names v8.1 shipped must keep resolving.
it('resolves a pre-restructuring class name to the class that replaced it', function (string $legacy, string $current): void {
    expect(class_exists($legacy) || enum_exists($legacy))->toBeTrue()
        ->and((new \ReflectionClass($legacy))->getName())->toBe($current);
})->with([
    'Client\WS' => ['Discord\Voice\Client\WS', \Discord\Voice\Gateway\WS::class],
    'Client\UDP' => ['Discord\Voice\Client\UDP', \Discord\Voice\Rtp\UDP::class],
    'Client\Packet' => ['Discord\Voice\Client\Packet', \Discord\Voice\Rtp\Packet::class],
    'Client\RtpHeader' => ['Discord\Voice\Client\RtpHeader', \Discord\Voice\Rtp\RtpHeader::class],
    'Client\HeaderValuesEnum' => ['Discord\Voice\Client\HeaderValuesEnum', \Discord\Voice\Rtp\HeaderValuesEnum::class],
    'Client\User' => ['Discord\Voice\Client\User', \Discord\Voice\Receive\User::class],
    'OggPage' => ['Discord\Voice\OggPage', \Discord\Voice\Ogg\OggPage::class],
    'OggStream' => ['Discord\Voice\OggStream', \Discord\Voice\Ogg\OggStream::class],
    'OpusHead' => ['Discord\Voice\OpusHead', \Discord\Voice\Ogg\OpusHead::class],
    'OpusTags' => ['Discord\Voice\OpusTags', \Discord\Voice\Ogg\OpusTags::class],
]);

it('keeps legacy type hints working against the moved classes', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $packet = new \Discord\Voice\Rtp\Packet('opus', 1, 2, 3, false, $key, null, null, 4, \Discord\Voice\Rtp\EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE);

    $legacyListener = static fn (\Discord\Voice\Client\Packet $p): int => $p->getSequence();

    expect($legacyListener($packet))->toBe(2)
        ->and(\Discord\Voice\Client\HeaderValuesEnum::AUTH_TAG_LENGTH)->toBe(\Discord\Voice\Rtp\HeaderValuesEnum::AUTH_TAG_LENGTH);
});
