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

namespace Discord\Voice\Concerns;

use Discord\Helpers\Collection;
use Discord\Voice\Processes\OpusFfi;
use Discord\Voice\Recording\RecordingFormat;
use Discord\Voice\Speaking;

/**
 * Recording concern of {@see \Discord\Voice\VoiceClient}: {@see record()} /
 * {@see stopRecording()} and the per-user file-sink lifecycle.
 *
 * @since 10.20.0
 */
trait ManagesRecording
{
    /**
     * Starts recording incoming voice audio.
     *
     * When called with no arguments, raw PCM and Opus frames are emitted via the
     * `channel-pcm` and `channel-opus` events for the caller to handle.
     *
     * When called with a format and output path callback, the voice client
     * automatically writes per-user audio files. The callback receives the user ID
     * string and must return an absolute or relative file path string.
     *
     * Supported formats:
     *  - `RecordingFormat::PCM` — raw s16le PCM; emits `channel-pcm` events AND writes raw bytes to
     *                             per-user files when `$outputPath` is provided
     *  - `RecordingFormat::WAV` — per-user WAV files via pure-PHP WavWriter
     *  - `RecordingFormat::OGG` — per-user OGG Opus files via ffmpeg (requires ffmpeg)
     *
     * @param RecordingFormat|null            $format     Output format. Null = PCM events only (no file writing).
     * @param (callable(string): string)|null $outputPath Callback returning the file path for a given user ID.
     *                                                    Required for WAV and OGG formats. Optional for PCM
     *                                                    (when provided, raw PCM bytes are written to the file).
     *
     * @throws \RuntimeException         if already recording.
     * @throws \InvalidArgumentException if $format is non-null/non-PCM but $outputPath is null.
     */
    public function record(?RecordingFormat $format = null, ?callable $outputPath = null): void
    {
        if ($this->shouldRecord) {
            throw new \RuntimeException('Already recording audio.');
        }

        if ($format !== null && $format !== RecordingFormat::PCM && $outputPath === null) {
            throw new \InvalidArgumentException('An $outputPath callback is required when recording to a file format.');
        }

        // Auto-initialize the FFI Opus decoder if not already set.
        // This enables the channel-pcm event output path without requiring
        // callers to manually invoke setDecoder().
        if ($this->opusdecoder === null && OpusFfi::isAvailable()) {
            $this->opusdecoder = new OpusFfi();
        }

        $this->recordingFormat = $format;
        $this->recordingOutputPath = $outputPath !== null ? \Closure::fromCallable($outputPath) : null;

        $this->shouldRecord = true;
        $this->discord->getLogger()->info('Started recording audio.');
    }

    /**
     * Stops an in-progress recording started by {@see record()}.
     *
     * Finalises every per-user writer, closes recording processes and PCM handles,
     * and clears all receive-stream, decoder and speaking state.
     *
     * @throws \RuntimeException if no recording is in progress.
     */
    public function stopRecording(): void
    {
        if (! $this->shouldRecord) {
            throw new \RuntimeException('Not recording audio.');
        }

        $this->shouldRecord = false;
        $this->discord->getLogger()->info('Stopped recording audio.');

        foreach ($this->recordingWriters as $writer) {
            try {
                $writer->finalize();
            } catch (\Throwable $e) {
                $this->discord->getLogger()->warning('Failed to finalize recording.', ['path' => $writer->getPath(), 'error' => $e->getMessage()]);
            }
        }
        $this->recordingWriters = [];

        foreach ($this->recordingProcesses as $process) {
            if ($process->isRunning()) {
                $process->stdin->close();
            }
        }
        $this->recordingProcesses = [];

        foreach ($this->recordingPcmHandles as $handle) {
            fclose($handle);
        }
        $this->recordingPcmHandles = [];

        $this->recordingFormat = null;
        $this->recordingOutputPath = null;

        $this->reset();

        foreach ($this->voiceDecoders as $decoder) {
            $decoder->close();
        }

        $this->voiceDecoders = [];
        $this->ffiDecoders = [];
        $this->receiveStreams = [];
        $this->speakingStatus = Collection::for(Speaking::class, 'ssrc');
        $this->ssrcToUserId = [];
    }
}
