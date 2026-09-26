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

use Discord\Voice\Rtp\EncryptionMode;
use Discord\Voice\Rtp\HeaderValuesEnum;
use Discord\Voice\Rtp\Packet;
use PHPUnit\Framework\TestCase;

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

it('aead_xchacha20_poly1305_rtpsize: roundtrips a payload with a known key, sequence, timestamp and SSRC', function (): void {
    skipUnlessRoundtripModeAvailable($this, EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE);

    $mode = EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE;
    $key = str_repeat("\x42", SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $payload = 'opus-frame-payload-bytes';
    $ssrc = 0xDEADBEEF;
    $seq = 0x1234;
    $timestamp = 0x01020304;
    $nonceCounter = 0x55667788;

    $outbound = new Packet($payload, $ssrc, $seq, $timestamp, false, $key, null, null, $nonceCounter, $mode);
    $wire = $outbound->getEncryptedMessage();

    $headerLen = HeaderValuesEnum::RTP_HEADER_OR_NONCE_LENGTH->value;
    $expectedHeader = pack('CCnNN', 0x80, 0x78, $seq, $timestamp, $ssrc);

    expect(substr($wire, 0, $headerLen))->toBe($expectedHeader)
        ->and(substr($wire, -4))->toBe(pack('V', $nonceCounter));

    $inbound = new Packet($wire, null, null, null, true, $key, mode: $mode);

    expect($inbound->getAudioData())->toBe($payload)
        ->and($inbound->getSequence())->toBe($seq)
        ->and($inbound->getTimestamp())->toBe($timestamp)
        ->and($inbound->getSSRC())->toBe($ssrc)
        ->and($inbound->getHeader())->toBe($expectedHeader);
});

it('aead_xchacha20_poly1305_rtpsize: wire bytes are the header, the XChaCha20-Poly1305 ciphertext and the 4-byte nonce', function (): void {
    skipUnlessRoundtripModeAvailable($this, EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE);

    $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $payload = random_bytes(160);
    $nonceCounter = 0x01020304;

    $wire = (new Packet($payload, 7, 8, 9, false, $key, null, null, $nonceCounter, EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE))
        ->getEncryptedMessage();

    // Computed with libsodium directly: RTP header as additional data, the nonce counter
    // zero-padded to XChaCha20's 24 bytes, only its 4 counter bytes on the wire.
    $header = pack('CCnNN', 0x80, 0x78, 8, 9, 7);
    $nonce = pack('V', $nonceCounter);
    $sealed = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
        $payload,
        $header,
        str_pad($nonce, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES, "\0", STR_PAD_RIGHT),
        $key,
    );

    expect($wire)->toBe($header.$sealed.$nonce)
        ->and(strlen($wire))->toBe(strlen($header) + strlen($payload) + HeaderValuesEnum::AUTH_TAG_LENGTH->value + 4);
});

it('aead_xchacha20_poly1305_rtpsize: roundtrips random payloads of varying sizes', function (int $size): void {
    skipUnlessRoundtripModeAvailable($this, EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE);

    $mode = EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE;
    $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $payload = random_bytes($size);

    $outbound = new Packet($payload, 1, 2, 3, false, $key, null, null, 7, $mode);
    $inbound = new Packet($outbound->getEncryptedMessage(), null, null, null, true, $key, mode: $mode);

    expect($inbound->getAudioData())->toBe($payload);
})->with([
    'tiny' => [1],
    'small' => [20],
    'opus-frame' => [160],
    'large' => [1275],
]);

it('aead_xchacha20_poly1305_rtpsize: each (key, nonce-counter) pair produces unique ciphertext', function (): void {
    skipUnlessRoundtripModeAvailable($this, EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE);

    $mode = EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE;
    $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);

    $a = new Packet('identical-payload', 1, 1, 1, false, $key, null, null, 0, $mode);
    $b = new Packet('identical-payload', 1, 1, 1, false, $key, null, null, 1, $mode);

    expect($a->getEncryptedMessage())->not->toBe($b->getEncryptedMessage());
});

it('aead_xchacha20_poly1305_rtpsize: tampering with the ciphertext fails authentication', function (): void {
    skipUnlessRoundtripModeAvailable($this, EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE);

    $mode = EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE;
    $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $wire = (new Packet('payload', 9, 9, 9, false, $key, null, null, 0, $mode))->getEncryptedMessage();

    $headerLen = HeaderValuesEnum::RTP_HEADER_OR_NONCE_LENGTH->value;
    $tampered = substr_replace($wire, chr(ord($wire[$headerLen]) ^ 0xFF), $headerLen, 1);

    expect((new Packet($tampered, null, null, null, true, $key, mode: $mode))->getAudioData())->toBeFalse();
});

it('aead_xchacha20_poly1305_rtpsize: strips the RTP extension payload before DAVE frame decryption', function (): void {
    skipUnlessRoundtripModeAvailable($this, EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE);

    $mode = EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE;
    $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $wire = buildRoundtripExtensionPacket($mode, $key, 5, 960, 12345, 'opus-audio', 'EXT!');
    $daveInput = null;

    $packet = new Packet(
        $wire,
        key: $key,
        inboundFrameDecryptor: function (string $frame) use (&$daveInput): string {
            $daveInput = $frame;

            return 'dave-decrypted-opus';
        },
        mode: $mode,
    );

    expect($daveInput)->toBe('opus-audio')
        ->and($packet->getAudioData())->toBe('dave-decrypted-opus');
});

it('aead_xchacha20_poly1305_rtpsize: keeps the DAVE layer inside the transport layer', function (): void {
    skipUnlessRoundtripModeAvailable($this, EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE);

    $mode = EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE;
    $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);

    $outbound = new Packet('opus', 1, 2, 3, false, $key, fn (string $frame): string => "dave({$frame})", null, 4, $mode);
    $transportOnly = new Packet($outbound->getEncryptedMessage(), null, null, null, true, $key, mode: $mode);
    $withDave = new Packet(
        $outbound->getEncryptedMessage(),
        key: $key,
        inboundFrameDecryptor: fn (string $frame): string => substr($frame, 5, -1),
        mode: $mode,
    );

    expect($transportOnly->getAudioData())->toBe('dave(opus)')
        ->and($withDave->getAudioData())->toBe('opus');
});

it('a packet sealed with one mode does not open with the other', function (EncryptionMode $sealedWith, EncryptionMode $openedWith): void {
    skipUnlessRoundtripModeAvailable($this, $sealedWith);
    skipUnlessRoundtripModeAvailable($this, $openedWith);

    $key = random_bytes(32);
    $wire = (new Packet('payload', 1, 2, 3, false, $key, null, null, 4, $sealedWith))->getEncryptedMessage();

    expect((new Packet($wire, null, null, null, true, $key, mode: $openedWith))->getAudioData())->toBeFalse()
        ->and((new Packet($wire, null, null, null, true, $key, mode: $sealedWith))->getAudioData())->toBe('payload');
})->with([
    'AES-256-GCM opened as XChaCha20-Poly1305' => [EncryptionMode::AEAD_AES256_GCM_RTPSIZE, EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE],
    'XChaCha20-Poly1305 opened as AES-256-GCM' => [EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE, EncryptionMode::AEAD_AES256_GCM_RTPSIZE],
]);

it('defaults to aead_aes256_gcm_rtpsize when no mode is given', function (): void {
    skipUnlessRoundtripModeAvailable($this, EncryptionMode::AEAD_AES256_GCM_RTPSIZE);

    $key = random_bytes(32);
    $wire = (new Packet('payload', 1, 2, 3, false, $key, null, null, 4))->getEncryptedMessage();

    expect((new Packet($wire, null, null, null, true, $key, mode: EncryptionMode::AEAD_AES256_GCM_RTPSIZE))->getAudioData())->toBe('payload');
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function skipUnlessRoundtripModeAvailable(TestCase $test, EncryptionMode $mode): void
{
    if (! $mode->isAvailable()) {
        $test->markTestSkipped("{$mode->value} is not available in this libsodium build.");
    }
}

function buildRoundtripExtensionPacket(
    EncryptionMode $mode,
    string $key,
    int $seq,
    int $timestamp,
    int $ssrc,
    string $audio,
    string $extensionData
): string {
    $header = pack('CCnNN', 0x90, 0x78, $seq, $timestamp, $ssrc)."\xBE\xDE".pack('n', intdiv(strlen($extensionData), 4));
    $nonce = pack('V', $seq - 1);
    $paddedNonce = str_pad($nonce, $mode->nonceLength(), "\0", STR_PAD_RIGHT);

    return $header.$mode->encrypt($extensionData.$audio, $header, $paddedNonce, $key).$nonce;
}
