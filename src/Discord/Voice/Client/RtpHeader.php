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

namespace Discord\Voice\Client;

final class RtpHeader
{
    private const BASE_HEADER_SIZE = 12;

    /**
     * Returns the fixed-size portion of an RTP header in bytes (base 12 + CSRC list).
     * Does not include any extension header.
     */
    public static function headerSize(string $packet): int
    {
        // byte 0: V(2) P(1) X(1) CC(4)
        // CC = CSRC count occupies bits 0-3 of byte 0
        $csrcCount = ord($packet[0]) & 0x0F;

        return self::BASE_HEADER_SIZE + 4 * $csrcCount;
    }

    /**
     * Returns true when the RTP extension bit (X) is set in the packet header.
     */
    public static function hasExtension(string $packet): bool
    {
        return (ord($packet[0]) & 0x10) !== 0;
    }

    /**
     * Strips the RTP extension header and its payload from $audio.
     *
     * The RTP extension sits immediately after the fixed header.
     * Its size is: 4 (two-byte profile/type + two-byte length in 32-bit words) + length * 4.
     *
     * Returns $audio unchanged if there is no extension.
     */
    public static function stripExtensionPayload(string $audio, int $headerSize): string
    {
        if (! self::hasExtension($audio)) {
            return $audio;
        }

        if (strlen($audio) <= $headerSize + 4) {
            return $audio;
        }

        // The extension length field (in 32-bit words) is at bytes $headerSize+2 and $headerSize+3
        $extLen = (ord($audio[$headerSize + 2]) << 8) | ord($audio[$headerSize + 3]);
        $extTotalBytes = 4 + $extLen * 4;

        return substr($audio, 0, $headerSize).substr($audio, $headerSize + $extTotalBytes);
    }
}
