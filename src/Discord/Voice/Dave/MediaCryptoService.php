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

namespace Discord\Voice\Dave;

use Psr\Log\LoggerInterface;

final class MediaCryptoService
{
    public function __construct(
        private readonly State $state,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Encrypt an outbound DAVE media frame. Returns original frame if passthrough.
     *
     * @param ?int $ssrc The local SSRC for the outbound RTP stream.
     */
    public function encrypt(string $frame, ?int $ssrc): string
    {
        $protocolVersion = $this->state->protocolVersion;
        if ($protocolVersion <= 0 || $this->state->passthroughMode) {
            return $frame;
        }

        $encrypted = null;

        if ($this->state->encryptor !== null && $ssrc !== null) {
            $encrypted = Runtime::encryptWithEncryptor($this->state->encryptor, $frame, $ssrc);
        }

        if (! is_string($encrypted)) {
            $encrypted = Runtime::encryptMediaFrame($frame, $protocolVersion);
        }

        if (! is_string($encrypted)) {
            $this->state->incrementEncryptFailures();

            if ($this->state->encryptFailureCount % 100 === 0) {
                $this->logger->warning('DAVE encrypt failure count: '.$this->state->encryptFailureCount, [
                    'protocol_version' => $protocolVersion,
                ]);
            }

            $this->logger->error('Failed to encrypt outgoing DAVE frame; dropping frame to preserve E2EE integrity.', [
                'protocol_version' => $protocolVersion,
                'frame_length' => strlen($frame),
                'ssrc' => $ssrc,
            ]);

            // Return unencrypted only when DAVE is in passthrough mode (not yet active).
            // If DAVE is active and encryption failed, drop the frame rather than
            // sending plaintext audio which would silently break E2EE.
            if ($this->state->passthroughMode) {
                return $frame;
            }

            return '';
        }

        return $encrypted;
    }

    /**
     * Decrypt an inbound DAVE media frame. Returns original frame if passthrough or no decryptor.
     *
     * @param ?string $userId The sender's user ID (resolved from SSRC by the caller).
     * @param ?int    $ssrc   The sender's SSRC, used only for logging on failure.
     */
    public function decrypt(string $frame, ?string $userId, ?int $ssrc = null): string|false
    {
        $protocolVersion = $this->state->protocolVersion;
        if ($protocolVersion <= 0 || $this->state->passthroughMode) {
            return $frame;
        }

        $decrypted = null;

        $targetDecryptor = $userId !== null ? $this->state->getDecryptor($userId) : null;

        if ($targetDecryptor !== null) {
            $decrypted = Runtime::decryptWithDecryptor($targetDecryptor, $frame);
        } else {
            // No per-user decryptor resolved — SSRC not yet mapped to a userId (speaking event
            // delayed) or the user is not yet in the MLS group. Iterate all known decryptors;
            // AEAD authentication failures are non-mutating so trying the wrong handle is safe.
            foreach ($this->state->getAllDecryptors() as $decryptor) {
                $attempt = Runtime::decryptWithDecryptor($decryptor, $frame);
                if (is_string($attempt)) {
                    $decrypted = $attempt;
                    break;
                }
                if ($attempt === false) {
                    $decrypted = false;
                    break;
                }
            }
        }

        if (! is_string($decrypted) && $decrypted !== false) {
            $decrypted = Runtime::decryptMediaFrame($frame, $protocolVersion);
        }

        if ($decrypted === false) {
            return false;
        }

        if ($decrypted === null) {
            $this->state->incrementDecryptFailures();

            if ($this->state->decryptFailureCount % 100 === 0) {
                $this->logger->warning('DAVE decrypt failure count: '.$this->state->decryptFailureCount, [
                    'protocol_version' => $protocolVersion,
                ]);
            }

            $this->logger->warning('Failed to decrypt incoming DAVE frame.', [
                'protocol_version' => $protocolVersion,
                'frame_length' => strlen($frame),
                'ssrc' => $ssrc,
            ]);

            return false;
        }

        // remove comment to enable DAVE frame logging, which can be helpful for debugging DAVE issues.
        /* $this->logger->debug('Decrypted incoming DAVE frame.', [
            'protocol_version' => $protocolVersion,
            'frame_length' => strlen($decrypted),
        ]); */

        return $decrypted;
    }
}
