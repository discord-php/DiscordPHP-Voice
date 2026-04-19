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

use Discord\Voice\ByteBuffer\Buffer;
use Discord\Voice\Client\HeaderValuesEnum;
use Discord\Voice\Client\Packet;

it('encrypts outbound frames and decrypts inbound frames with callbacks', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    $decodedPacket = null;

    $outbound = new Packet(
        'audio',
        0x10203040,
        17,
        960,
        false,
        $key,
        fn (string $frame): string => "enc:$frame"
    );

    $inbound = new Packet(
        $outbound->getEncryptedMessage(),
        null,
        null,
        null,
        true,
        $key,
        null,
        function (string $frame, Packet $packet) use (&$decodedPacket): string {
            $decodedPacket = $packet;

            return "dec:$frame";
        }
    );

    expect($outbound->getAudioData())->toBe('enc:audio')
        ->and($outbound->getHeader())->toBe(substr($outbound->getEncryptedMessage(), 0, HeaderValuesEnum::RTP_HEADER_OR_NONCE_LENGTH->value))
        ->and($inbound->getAudioData())->toBe('dec:enc:audio')
        ->and($inbound->getSequence())->toBe(17)
        ->and($inbound->getTimestamp())->toBe(960)
        ->and($inbound->getSSRC())->toBe(0x10203040)
        ->and($inbound->getHeader())->toBe(substr($outbound->getEncryptedMessage(), 0, HeaderValuesEnum::RTP_HEADER_OR_NONCE_LENGTH->value))
        ->and($inbound->getEncryptedMessage())->toBe($outbound->getEncryptedMessage())
        ->and($decodedPacket)->toBe($inbound);
});

it('keeps outbound audio when the frame encryptor does not return a string', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);

    $packet = new Packet(
        'audio',
        7,
        3,
        123,
        false,
        $key,
        fn (string $frame): int => strlen($frame)
    );

    expect($packet->getAudioData())->toBe('audio')
        ->and((new Packet($packet->getEncryptedMessage(), null, null, null, true, $key))->getAudioData())->toBe('audio');
});

it('strips RTP extension payload from decrypted audio', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    $rawPacket = buildExtensionPacket($key, 21, 480, 99, 'voice-frame', 'EXT!');

    $packet = new Packet($rawPacket, null, null, null, true, $key);

    expect($packet->getHeader())->toBe(substr($rawPacket, 0, 16))
        ->and($packet->getAudioData())->toBe('voice-frame')
        ->and($packet->getSequence())->toBe(21)
        ->and($packet->getTimestamp())->toBe(480)
        ->and($packet->getSSRC())->toBe(99);
});

it('detects standard and extension RTP headers when setting the header', function (): void {
    $packet = packetWithoutConstructor();
    $standardMessage = pack('CCnNN', 0x80, 0x78, 1, 2, 3).'payload';
    $extensionMessage = pack('CCnNN', 0x90, 0x78, 1, 2, 3)."\xBE\xDE\x00\x01".'payload';

    expect($packet->setHeader($standardMessage))->toBe(substr($standardMessage, 0, 12))
        ->and($packet->setHeader($extensionMessage))->toBe(substr($extensionMessage, 0, 16));
});

it('returns null or false for empty and incomplete input', function (): void {
    $packet = packetWithoutConstructor();

    expect($packet->setHeader(''))->toBeNull()
        ->and($packet->decrypt(''))->toBeNull()
        ->and($packet->decrypt('abc'))->toBeFalse();
});

it('returns false when inbound decryption fails authentication', function (): void {
    $key = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    $outbound = new Packet('audio', 42, 99, 1234, false, $key);
    $tampered = $outbound->getEncryptedMessage();
    $offset = HeaderValuesEnum::RTP_HEADER_OR_NONCE_LENGTH->value;
    $tampered = substr_replace($tampered, chr(ord($tampered[$offset]) ^ 0xFF), $offset, 1);

    $packet = new Packet($tampered, null, null, null, true, $key);

    expect($packet->getAudioData())->toBeFalse()
        ->and($packet->getSequence())->toBe(99)
        ->and($packet->getTimestamp())->toBe(1234)
        ->and($packet->getSSRC())->toBe(42);
});

it('creates packets from raw buffers and exposes payload data', function (): void {
    $raw = pack('CCnNN', 0x80, 0x78, 0x1234, 0x01020304, 0x05060708).'payload';
    $packet = packetWithoutConstructor();

    expect($packet->setBuffer(Buffer::make($raw)))->toBe($packet)
        ->and($packet->getSequence())->toBe(0x1234)
        ->and($packet->getTimestamp())->toBe(0x01020304)
        ->and($packet->getSSRC())->toBe(0x05060708)
        ->and($packet->getData())->toBe('payload');

    $made = Packet::make($raw);

    expect($made->getSequence())->toBe(0x1234)
        ->and($made->getTimestamp())->toBe(0x01020304)
        ->and($made->getSSRC())->toBe(0x05060708)
        ->and($made->getData())->toBe('payload')
        ->and($made->getAudioData())->toBeNull();
});

function packetWithoutConstructor(): Packet
{
    return (new \ReflectionClass(Packet::class))->newInstanceWithoutConstructor();
}

function buildExtensionPacket(
    string $key,
    int $seq,
    int $timestamp,
    int $ssrc,
    string $audio,
    string $extensionData
): string {
    $header = pack('CCnNN', 0x90, 0x78, $seq, $timestamp, $ssrc)."\xBE\xDE".pack('n', intdiv(strlen($extensionData), 4));
    $nonce = pack('V', $seq - 1);
    $paddedNonce = str_pad($nonce, SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES, "\0", STR_PAD_RIGHT);
    $cipherText = sodium_crypto_aead_aes256gcm_encrypt($extensionData.$audio, $header, $paddedNonce, $key);

    return $header.$cipherText.$nonce;
}
