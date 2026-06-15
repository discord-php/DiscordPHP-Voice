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

namespace Discord\Voice\Recording;

use RuntimeException;

/**
 * Writes Discord voice PCM audio to a standard WAV file.
 *
 * Discord voice PCM is always 48000 Hz, stereo (2 channels), signed 16-bit
 * little-endian — these parameters are fixed and not configurable.
 */
class WavWriter
{
    private const SAMPLE_RATE = 48000;
    private const NUM_CHANNELS = 2;
    private const BITS_PER_SAMPLE = 16;

    /** @var resource|null */
    private $handle = null;

    private int $dataBytes = 0;

    public function __construct(private string $path)
    {
    }

    /**
     * Opens the output file and writes a 44-byte WAV header with placeholder
     * sizes. Must be called before {@see write()}.
     *
     * @throws RuntimeException if the file cannot be opened.
     */
    public function open(): void
    {
        if (! file_exists(dirname($this->path))) {
            mkdir(dirname($this->path), 0755, true);
        }

        $handle = fopen($this->path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("WavWriter: cannot open file for writing: {$this->path}");
        }

        $this->handle = $handle;
        $this->dataBytes = 0;

        fwrite($this->handle, $this->buildHeader(0));
    }

    /**
     * Appends raw PCM bytes to the file and accumulates the byte count.
     *
     * @throws RuntimeException if the writer has not been opened.
     */
    public function write(string $pcm): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('WavWriter: write() called before open().');
        }

        fwrite($this->handle, $pcm);
        $this->dataBytes += strlen($pcm);
    }

    /**
     * Seeks back to offset 0 and rewrites the complete WAV header with the
     * correct RIFF chunk size and data sub-chunk size, then closes the file.
     *
     * @throws RuntimeException if the writer has not been opened.
     */
    public function finalize(): void
    {
        if ($this->handle === null) {
            throw new RuntimeException('WavWriter: finalize() called before open().');
        }

        rewind($this->handle);
        fwrite($this->handle, $this->buildHeader($this->dataBytes));
        fclose($this->handle);

        $this->handle = null;
    }

    /** Returns the path this writer writes to. */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Builds a 44-byte WAV header for the given data size.
     *
     * Header layout (all values little-endian):
     *   0– 3  "RIFF"
     *   4– 7  chunkSize      = 36 + dataBytes
     *   8–11  "WAVE"
     *  12–15  "fmt "
     *  16–19  subchunk1Size  = 16  (PCM)
     *  20–21  audioFormat    = 1   (PCM)
     *  22–23  numChannels    = 2
     *  24–27  sampleRate     = 48000
     *  28–31  byteRate       = sampleRate * numChannels * (bitsPerSample/8)
     *  32–33  blockAlign     = numChannels * (bitsPerSample/8)
     *  34–35  bitsPerSample  = 16
     *  36–39  "data"
     *  40–43  subchunk2Size  = dataBytes
     */
    private function buildHeader(int $dataBytes): string
    {
        $byteRate = self::SAMPLE_RATE * self::NUM_CHANNELS * (self::BITS_PER_SAMPLE / 8);
        $blockAlign = self::NUM_CHANNELS * (self::BITS_PER_SAMPLE / 8);

        return 'RIFF'
            .pack('V', 36 + $dataBytes)
            .'WAVE'
            .'fmt '
            .pack('V', 16)
            .pack('v', 1)
            .pack('v', self::NUM_CHANNELS)
            .pack('V', self::SAMPLE_RATE)
            .pack('V', $byteRate)
            .pack('v', $blockAlign)
            .pack('v', self::BITS_PER_SAMPLE)
            .'data'
            .pack('V', $dataBytes);
    }
}
