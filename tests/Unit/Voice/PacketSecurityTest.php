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

// ---------------------------------------------------------------------------
// Malformed / short-packet rejection (UDP-level guard is 8 bytes; Packet-
// level guard kicks in for 9-31 byte messages that slip through).
// ---------------------------------------------------------------------------

it('Packet constructor on a 12-byte header-only message does not throw and yields null audio (SHORT-1)', function () {
    // 12 bytes = exactly one RTP header, zero payload.
    // unpack() succeeds (12 bytes ≥ 12), but
    // encryptedLength = 12 - 12 (header) - 16 (auth tag) - 4 (nonce) = -20 < 0.
    // The early `return false` in decrypt() fires BEFORE the try/finally block,
    // so $decryptedAudio is never assigned — getAudioData() therefore returns null.
    // This is a production inconsistency: decrypt() returns false but the property
    // stays uninitialized, so the public accessor silently returns null instead.
    $key = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    $packet = new Packet(str_repeat("\x80", 12), key: $key, decrypt: true);

    expect($packet->getAudioData())->toBeNull();
});

it('Packet constructor on a 25-byte message too short for AEAD tag plus nonce does not throw (SHORT-2)', function () {
    // 25 bytes: unpack() succeeds (12-byte header is present), but
    // encryptedLength = 25 - 12 - 16 - 4 = -7 < 0.
    // Same early-return as SHORT-1: $decryptedAudio unset, getAudioData() = null.
    $key = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    $packet = new Packet(str_repeat("\x80", 25), key: $key, decrypt: true);

    expect($packet->getAudioData())->toBeNull();
});

it('explicit decrypt call on a 20-byte message returns false while getAudioData still returns null (SHORT-3)', function () {
    // 20 bytes: unpack() extracts header fields cleanly (≥ 12 bytes, no warning).
    // encryptedLength = 20 - 12 - 16 - 4 = -12 < 0.
    // decrypt() return value = false (correct signal) but $decryptedAudio is NOT
    // updated (the early `return false` precedes the try/finally), so getAudioData()
    // reports null — a production inconsistency that this test documents explicitly.
    $key = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    $raw = str_repeat("\x80", 20);

    $packet = new Packet($raw, key: $key, decrypt: true);
    // Property not updated by early-return path.
    expect($packet->getAudioData())->toBeNull();

    // Re-invoking decrypt() explicitly does return false.
    expect($packet->decrypt($raw))->toBeFalse();
});
