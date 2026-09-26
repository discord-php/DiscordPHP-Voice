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

it('negotiate prefers aead_aes256_gcm_rtpsize when it is offered and hardware AES is available', function (): void {
    $offered = ['aead_xchacha20_poly1305_rtpsize', 'aead_aes256_gcm_rtpsize', 'xsalsa20_poly1305'];

    // The offer order is the server's, not a preference: AES-256-GCM still wins where it can run.
    expect(EncryptionMode::negotiate($offered))->toBe(
        sodium_crypto_aead_aes256gcm_is_available()
            ? EncryptionMode::AEAD_AES256_GCM_RTPSIZE
            : EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE
    );
});

it('negotiate falls back to aead_xchacha20_poly1305_rtpsize when AES-256-GCM is not offered', function (): void {
    expect(EncryptionMode::negotiate(['xsalsa20_poly1305_lite', 'aead_xchacha20_poly1305_rtpsize']))
        ->toBe(EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE);
});

it('negotiate returns null when the offer contains nothing this library implements', function (array $offered): void {
    expect(EncryptionMode::negotiate($offered))->toBeNull();
})->with([
    'empty' => [[]],
    'deprecated modes only' => [['xsalsa20_poly1305', 'xsalsa20_poly1305_suffix', 'xsalsa20_poly1305_lite', 'aead_aes256_gcm']],
    'not strings' => [[null, 1, ['aead_xchacha20_poly1305_rtpsize']]],
]);

it('isAvailable mirrors what libsodium on this machine implements', function (): void {
    expect(EncryptionMode::AEAD_AES256_GCM_RTPSIZE->isAvailable())->toBe(sodium_crypto_aead_aes256gcm_is_available())
        ->and(EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE->isAvailable())->toBeTrue();
});

it('nonceLength matches the cipher: 12 bytes for AES-256-GCM, 24 for XChaCha20-Poly1305', function (): void {
    expect(EncryptionMode::AEAD_AES256_GCM_RTPSIZE->nonceLength())->toBe(12)
        ->and(EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE->nonceLength())->toBe(24);
});

it('encrypt and decrypt use the cipher the mode names', function (EncryptionMode $mode, callable $open): void {
    if (! $mode->isAvailable()) {
        $this->markTestSkipped("{$mode->value} is not available in this libsodium build.");
    }

    $key = random_bytes(32);
    $nonce = random_bytes($mode->nonceLength());
    $sealed = $mode->encrypt('frame', 'header', $nonce, $key);

    expect($open($sealed, 'header', $nonce, $key))->toBe('frame')
        ->and($mode->decrypt($sealed, 'header', $nonce, $key))->toBe('frame')
        ->and($mode->decrypt($sealed, 'other header', $nonce, $key))->toBeFalse();
})->with([
    'aead_aes256_gcm_rtpsize' => [EncryptionMode::AEAD_AES256_GCM_RTPSIZE, 'sodium_crypto_aead_aes256gcm_decrypt'],
    'aead_xchacha20_poly1305_rtpsize' => [EncryptionMode::AEAD_XCHACHA20_POLY1305_RTPSIZE, 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt'],
]);
