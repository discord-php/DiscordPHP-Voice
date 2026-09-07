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

namespace Discord\Voice\Processes;

use React\ChildProcess\Process;

/**
 * Handles the encoding and decoding of audio streams using DCA format.
 *
 * @since 10.19.0
 */
final class DCA extends ProcessAbstract
{
    /**
     * The DCA version the client is using.
     *
     * @var string The DCA version.
     */
    public const DCA_VERSION = 'DCA1';

    protected static string $exec = 'dca';

    /**
     * Locates a `dca` executable on PATH, caching the resolved path in `self::$exec`.
     *
     * @return bool True if a usable binary was found.
     */
    public static function checkForDca(): bool
    {
        $binaries = [
            'dca',
        ];

        foreach ($binaries as $binary) {
            $output = self::checkForExecutable($binary);

            if (null !== $output) {
                self::$exec = $output;

                return true;
            }
        }

        return false;
    }

    /**
     * Encodes audio to DCA format.
     *
     * @param string|null $filename The input filename, or null for pipe input.
     * @param int|float   $volume   The volume adjustment in dB.
     * @param int         $bitrate  The bitrate for the output audio.
     * @param array|null  $preArgs  Additional arguments to pass before the main flags.
     *
     * @TODO Implement function, was not in original code.
     *
     * @return Process
     */
    public static function encode(
        ?string $filename = null,
        int|float $volume = 0,
        int $bitrate = 128000,
        ?array $preArgs = null
    ): Process {
        $flags = [
            '-ab', round($bitrate / 1000), // Bitrate
            '-mode', 'decode', // Decode mode
        ];

        $flags = implode(' ', $flags);

        return new Process(self::$exec." $flags");
    }

    /**
     * Builds a `dca` decode process.
     *
     * @param string|null        $filename  Input filename, or null to read from stdin.
     * @param int|float           $volume    Volume adjustment in dB.
     * @param int                 $bitrate   Target bitrate in bits per second.
     * @param int                 $channels  Output channel count.
     * @param int|null            $frameSize Samples per frame; defaults to 960 (20 ms at 48 kHz).
     * @param array<int|string>|null $preArgs  Extra arguments placed before the main flags.
     */
    public static function decode(
        ?string $filename = null,
        int|float $volume = 0,
        int $bitrate = 128000,
        int $channels = 2,
        ?int $frameSize = null,
        ?array $preArgs = null,
    ): Process {
        if (null === $frameSize) {
            $frameSize = 960; // 20ms at 48kHz
        }

        $flags = [
        ];

        $flags = implode(' ', $flags);

        return new Process(self::$exec." $flags");
    }
}
