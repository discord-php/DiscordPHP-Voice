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

use Discord\Voice\Client\HeaderValuesEnum;
use Discord\Voice\Client\Packet;

it('aead_aes256_gcm_rtpsize: roundtrips a payload with a known key, sequence, timestamp and SSRC', function (): void {
    if (! sodium_crypto_aead_aes256gcm_is_available()) {
        $this->markTestSkipped('aead_aes256_gcm_rtpsize requires libsodium AES-256-GCM hardware support.');
    }

    $key = str_repeat("\x42", SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    $payload = 'opus-frame-payload-bytes';
    $ssrc = 0xDEADBEEF;
    $seq = 0x1234;
    $timestamp = 0x01020304;
    $nonceCounter = 0x55667788;

    $outbound = new Packet($payload, $ssrc, $seq, $timestamp, false, $key, null, null, $nonceCounter);
    $wire = $outbound->getEncryptedMessage();

    $headerLen = HeaderValuesEnum::RTP_HEADER_OR_NONCE_LENGTH->value;
    $expectedHeader = pack('CCnNN', 0x80, 0x78, $seq, $timestamp, $ssrc);

    expect(substr($wire, 0, $headerLen))->toBe($expectedHeader)
        ->and($outbound->getHeader())->toBe($expectedHeader)
        ->and(substr($wire, -4))->toBe(pack('V', $nonceCounter));

    $inbound = new Packet($wire, null, null, null, true, $key);

    expect($inbound->getAudioData())->toBe($payload)
        ->and($inbound->getSequence())->toBe($seq)
        ->and($inbound->getTimestamp())->toBe($timestamp)
        ->and($inbound->getSSRC())->toBe($ssrc)
        ->and($inbound->getHeader())->toBe($expectedHeader);
});

it('aead_aes256_gcm_rtpsize: roundtrips random payloads of varying sizes', function (int $size): void {
    if (! sodium_crypto_aead_aes256gcm_is_available()) {
        $this->markTestSkipped('aead_aes256_gcm_rtpsize requires libsodium AES-256-GCM hardware support.');
    }

    $key = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    $payload = random_bytes($size);

    $outbound = new Packet($payload, 1, 2, 3, false, $key, null, null, 7);
    $inbound = new Packet($outbound->getEncryptedMessage(), null, null, null, true, $key);

    expect($inbound->getAudioData())->toBe($payload);
})->with([
    'tiny' => [1],
    'small' => [20],
    'opus-frame' => [160],
    'large' => [1275],
]);

it('aead_aes256_gcm_rtpsize: each (key, nonce-counter) pair produces unique ciphertext', function (): void {
    if (! sodium_crypto_aead_aes256gcm_is_available()) {
        $this->markTestSkipped('aead_aes256_gcm_rtpsize requires libsodium AES-256-GCM hardware support.');
    }

    $key = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    $payload = 'identical-payload';

    $a = new Packet($payload, 1, 1, 1, false, $key, null, null, 0);
    $b = new Packet($payload, 1, 1, 1, false, $key, null, null, 1);

    expect($a->getEncryptedMessage())->not->toBe($b->getEncryptedMessage());

    $decA = new Packet($a->getEncryptedMessage(), null, null, null, true, $key);
    $decB = new Packet($b->getEncryptedMessage(), null, null, null, true, $key);

    expect($decA->getAudioData())->toBe($payload)
        ->and($decB->getAudioData())->toBe($payload);
});

it('aead_aes256_gcm_rtpsize: tampering with the ciphertext fails authentication', function (): void {
    if (! sodium_crypto_aead_aes256gcm_is_available()) {
        $this->markTestSkipped('aead_aes256_gcm_rtpsize requires libsodium AES-256-GCM hardware support.');
    }

    $key = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    $outbound = new Packet('payload', 9, 9, 9, false, $key, null, null, 0);
    $wire = $outbound->getEncryptedMessage();

    $headerLen = HeaderValuesEnum::RTP_HEADER_OR_NONCE_LENGTH->value;
    $tampered = substr_replace($wire, chr(ord($wire[$headerLen]) ^ 0xFF), $headerLen, 1);

    $inbound = new Packet($tampered, null, null, null, true, $key);

    expect($inbound->getAudioData())->toBeFalse();
});

it('aead_xchacha20_poly1305_rtpsize: roundtrip', function (): void {
    if (! defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES')) {
        $this->markTestSkipped('aead_xchacha20_poly1305_rtpsize requires libsodium XChaCha20-Poly1305-IETF support.');
    }

    $this->markTestSkipped(
        'aead_xchacha20_poly1305_rtpsize is not implemented in Discord\\Voice\\Client\\Packet; '
        .'only aead_aes256_gcm_rtpsize is currently supported. See Packet::encrypt()/decrypt().'
    );
});
