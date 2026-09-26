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

namespace Discord\Voice\Rtp;

/**
 * The voice transport encryption modes this library can negotiate.
 *
 * Both are "rtpsize" AEAD modes with the same packet layout:
 * `[RTP header][ciphertext][16-byte auth tag][4-byte nonce]`. The RTP header, up to and
 * including the extension preamble, is the additional data, and the 32-bit nonce counter
 * appended to the packet is zero-padded to the cipher's nonce length.
 *
 * Cases are declared in order of preference; {@see self::negotiate()} relies on it.
 *
 * @link https://discord.com/developers/docs/topics/voice-connections#transport-encryption-modes
 *
 * @since 8.2.0
 */
enum EncryptionMode: string
{
    /** AES-256-GCM. Discord's preferred mode, but libsodium only offers it on CPUs with hardware AES. */
    case AEAD_AES256_GCM_RTPSIZE = 'aead_aes256_gcm_rtpsize';

    /** XChaCha20-Poly1305. Discord requires every client to support it. */
    case AEAD_XCHACHA20_POLY1305_RTPSIZE = 'aead_xchacha20_poly1305_rtpsize';

    /**
     * Picks the mode to select from the voice server's offer: the most preferred mode that
     * is both offered and available on this machine.
     *
     * @param string[] $offered The `modes` list from the voice Ready payload (op 2).
     *
     * @return self|null Null when the offer contains no mode this machine can run.
     */
    public static function negotiate(array $offered): ?self
    {
        foreach (self::cases() as $mode) {
            if (in_array($mode->value, $offered, true) && $mode->isAvailable()) {
                return $mode;
            }
        }

        return null;
    }

    /** Returns true if libsodium on this machine implements the mode's cipher. */
    public function isAvailable(): bool
    {
        return match ($this) {
            self::AEAD_AES256_GCM_RTPSIZE => function_exists('sodium_crypto_aead_aes256gcm_is_available')
                && sodium_crypto_aead_aes256gcm_is_available(),
            self::AEAD_XCHACHA20_POLY1305_RTPSIZE => function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt'),
        };
    }

    /** Returns the cipher's nonce length in bytes: 12 for AES-256-GCM, 24 for XChaCha20-Poly1305. */
    public function nonceLength(): int
    {
        return match ($this) {
            self::AEAD_AES256_GCM_RTPSIZE => SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES,
            self::AEAD_XCHACHA20_POLY1305_RTPSIZE => SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES,
        };
    }

    /**
     * Encrypts a frame, returning the ciphertext with the 16-byte auth tag appended.
     *
     * @param string $message        The plaintext frame.
     * @param string $additionalData The RTP header.
     * @param string $nonce          The nonce, already padded to {@see self::nonceLength()}.
     * @param string $key            The 32-byte secret key from the session description.
     *
     * @throws \SodiumException If the cipher is unavailable or an argument has the wrong length.
     */
    public function encrypt(string $message, string $additionalData, string $nonce, string $key): string
    {
        return match ($this) {
            self::AEAD_AES256_GCM_RTPSIZE => sodium_crypto_aead_aes256gcm_encrypt($message, $additionalData, $nonce, $key),
            self::AEAD_XCHACHA20_POLY1305_RTPSIZE => sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($message, $additionalData, $nonce, $key),
        };
    }

    /**
     * Decrypts and authenticates a frame.
     *
     * @param string $ciphertext     The ciphertext with the 16-byte auth tag appended.
     * @param string $additionalData The RTP header.
     * @param string $nonce          The nonce, already padded to {@see self::nonceLength()}.
     * @param string $key            The 32-byte secret key from the session description.
     *
     * @throws \SodiumException If the cipher is unavailable or an argument has the wrong length.
     *
     * @return string|false The plaintext, or false if authentication fails.
     */
    public function decrypt(string $ciphertext, string $additionalData, string $nonce, string $key): string|false
    {
        return match ($this) {
            self::AEAD_AES256_GCM_RTPSIZE => sodium_crypto_aead_aes256gcm_decrypt($ciphertext, $additionalData, $nonce, $key),
            self::AEAD_XCHACHA20_POLY1305_RTPSIZE => sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $additionalData, $nonce, $key),
        };
    }
}
