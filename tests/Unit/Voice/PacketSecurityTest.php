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

use Discord\Voice\Client\Packet;

it('throws LogicException when encrypting without explicit nonce (CRIT-3)', function () {
    $key = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    $audio = random_bytes(160);

    new Packet($audio, seq: 100, timestamp: 1000, ssrc: 12345, decrypt: false, key: $key, nonce: null);
})->throws(\LogicException::class, 'Nonce must be set before encrypting');

it('encrypts successfully with explicit nonce', function () {
    $key = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    $audio = random_bytes(160);

    $packet = new Packet($audio, seq: 100, timestamp: 1000, ssrc: 12345, decrypt: false, key: $key, nonce: 42);
    expect($packet->getEncryptedMessage())->not->toBeNull();
});

it('returns false for truncated packets where encryptedLength < 0 (HIGH-8)', function () {
    $key = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    // A 20-byte message results in negative encryptedLength (needs >= headerSize + authTag + nonce = 32)
    $shortMessage = random_bytes(20);

    $packet = Packet::make($shortMessage);
    $result = $packet->decrypt($shortMessage);

    expect($result)->toBeFalse();
});

it('returns false on any AEAD failure regardless of ciphertext length (MED-2)', function () {
    $key = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    // 52 bytes = 12 header + 20 cipher + 16 auth tag + 4 nonce
    // The 20-byte ciphertext case used to be silently passed through
    $fakePacket = str_repeat("\x00", 12).random_bytes(20).random_bytes(16).pack('V', 1);

    $packet = new Packet($fakePacket, key: $key, decrypt: true);
    expect($packet->getAudioData())->toBeFalse();
});

it('does not treat the string zero as empty (LOW-1)', function () {
    // Build a valid-size packet so make() can read header fields from the buffer
    $validPacket = str_repeat("\x80", 12);
    $packet = Packet::make($validPacket);

    // Set rawData to the string "0" via reflection.
    // With the old empty() guard, "0" would be treated as empty and return null.
    // With the fixed check ($message === '' || $message === null), "0" passes through
    // and fails at header extraction instead, returning false (not null).
    $ref = new \ReflectionProperty($packet, 'rawData');
    $ref->setAccessible(true);
    $ref->setValue($packet, '0');

    $result = $packet->decrypt();
    expect($result)->toBeFalse();
});
