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

use Discord\Voice\Exceptions\Libraries\FFmpegNotFoundException;
use React\ChildProcess\Process;

/**
 * Handles the decoding and encoding of audio streams using FFmpeg.
 *
 * @since 10.19.0
 */
final class Ffmpeg extends ProcessAbstract
{
    protected static string $exec = '/usr/bin/ffmpeg';

    /**
     * @throws FFmpegNotFoundException if no ffmpeg binary can be located on PATH.
     */
    public function __construct()
    {
        if (! $this->checkForFFmpeg()) {
            throw new FFmpegNotFoundException('FFmpeg binary not found.');
        }
    }

    /**
     * Guards static `encode()` / `decode()` calls behind an ffmpeg availability check.
     *
     * @param string      $name      Method being called.
     * @param array<mixed> $arguments Forwarded arguments.
     *
     * @throws FFmpegNotFoundException  if ffmpeg is not installed.
     * @throws \BadMethodCallException  for any other method name.
     */
    public static function __callStatic(string $name, array $arguments)
    {
        if (method_exists(self::class, $name) && in_array($name, ['encode', 'decode'])) {
            if (! self::checkForFFmpeg()) {
                throw new FFmpegNotFoundException('FFmpeg binary not found.');
            }

            return self::$name(...$arguments);
        }

        throw new \BadMethodCallException("Method {$name} does not exist in ".__CLASS__);
    }

    /**
     * Locates an `ffmpeg` executable on PATH, caching the resolved path in `self::$exec`.
     *
     * @return bool True if a usable binary was found.
     */
    public static function checkForFFmpeg(): bool
    {
        $binaries = [
            'ffmpeg',
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
     * Builds an ffmpeg process that transcodes the input to Opus on stdout (`pipe:1`).
     *
     * @param string|null        $filename Input filename, or null to read from stdin (`pipe:0`).
     * @param int|float           $volume   Volume adjustment in dB.
     * @param int                 $bitrate  Target audio bitrate in bits per second.
     * @param array<string>|null  $preArgs  Extra arguments placed before the input flags.
     */
    public static function encode(
        ?string $filename = null,
        int|float $volume = 0,
        int $bitrate = 128000,
        ?array $preArgs = null
    ): Process {
        $flags = [
            '-protocol_whitelist', 'file,http,https,tcp,tls,crypto,pipe',
            '-fflags', '+nobuffer',
            '-i', escapeshellarg($filename ?? 'pipe:0'),
            '-map_metadata', '-1',
            '-f', 'opus',
            '-c:a', 'libopus',
            '-ar', parent::DEFAULT_KHZ,
            '-af', escapeshellarg("volume={$volume}dB"),
            '-ac', '2',
            '-b:a', $bitrate,
            '-loglevel', 'warning',
            'pipe:1',
        ];

        if (null !== $preArgs) {
            $flags = array_merge(array_map('escapeshellarg', $preArgs), $flags);
        }

        $flags = implode(' ', $flags);

        return new Process(
            self::$exec." {$flags}",
            fds: [
                ['socket'],
                ['socket'],
                ['socket'],
            ]
        );
    }

    /**
     * Decodes an Opus audio stream to OGG format using FFmpeg.
     *
     * TODO: Add support for Windows, currently only tested and ran on WSL2
     *
     * @param  mixed      $filename  If there's no name, it will output to stdout
     *                               (pipe:1). If a name is given, it will save the file
     *                               with the given name. If the name does not end with
     *                               .ogg, it will append .ogg to the name.
     *                               If null, it will use 'pipe:1' as the filename.
     * @param  int|float  $volume    Default: 0
     * @param  int        $bitrate   Default: 128000
     * @param  int        $channels  Default: 2
     * @param  null|int   $frameSize
     * @param  null|array $preArgs
     * @return Process
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
            $frameSize = round(20 * 48);
        }

        if ($filename) {
            $filename = sys_get_temp_dir().DIRECTORY_SEPARATOR.date('Y-m-d_H-i').'-'.$filename;
            if (! str_ends_with($filename, '.ogg')) {
                $filename .= '.ogg';
            }
        } elseif (null === $filename) {
            $filename = 'pipe:1';
        }

        $flags = [
            '-loglevel', 'error', // Set log level to warning to reduce output noise
            '-channel_layout', 'stereo',
            '-ac', $channels,
            '-ar', parent::DEFAULT_KHZ,
            '-f', 's16le',
            '-i', 'pipe:0',
            '-acodec', 'libopus',
            '-f', 'ogg',
            '-ar', parent::DEFAULT_KHZ,
            '-ac', $channels,
            '-b:a', $bitrate,
            escapeshellarg($filename),
        ];

        if (null !== $preArgs) {
            $flags = array_merge(array_map('escapeshellarg', $preArgs), $flags);
        }

        $flags = implode(' ', $flags);

        return new Process(self::$exec." {$flags}", fds: [['socket'], ['socket'], ['socket']]);
    }
}
