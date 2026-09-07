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

use Discord\Voice\Processes\Ffmpeg;
use Discord\Voice\Processes\OpusFfi;
use Discord\Voice\RecieveStream;
use Discord\Voice\ReceiveStream;
use Discord\Voice\Recording\RecordingFormat;
use Discord\Voice\Recording\WavWriter;
use Discord\Voice\Rtp\Packet;
use Discord\Voice\Speaking;
use React\ChildProcess\Process;

/**
 * Inbound-audio concern of {@see \Discord\Voice\VoiceClient}: SSRC/speaking
 * bookkeeping, per-user decoder processes and receive streams, and the
 * {@see handleAudioData()} decode path.
 *
 * @since 10.20.0
 */
trait ManagesReceiving
{
    /**
     * Checks if the user is speaking.
     *
     * @param string|int|null $id Either the User ID or SSRC (if null, return discords speaking status).
     *
     * @return bool Whether the user is speaking.
     */
    public function isSpeaking($id = null): bool
    {
        return match (true) {
            ! isset($id) => $this->speaking,
            $user = $this->speakingStatus->get('user_id', $id) => $user->speaking,
            $ssrc = $this->speakingStatus->get('ssrc', $id) => $ssrc->speaking,
            default => false,
        };
    }

    /**
     * Removes and closes the voice decoder associated with the given SSRC.
     *
     * @param object $ss An object containing the SSRC (Synchronization Source identifier).
     *                   Expected to have a property 'ssrc'.
     */
    protected function removeDecoder($ss): void
    {
        $decoder = $this->voiceDecoders[$ss->ssrc] ?? null;

        if (null === $decoder) {
            return; // no voice decoder to remove
        }

        if ($decoder->isRunning()) {
            $decoder->terminate(SIGTERM);
        }
        $decoder->close();
        unset(
            $this->voiceDecoders[$ss->ssrc],
            $this->speakingStatus[$ss->ssrc],
            $this->receiveStreams[$ss->ssrc],
            $this->ssrcToUserId[$ss->ssrc]
        );
    }

    /**
     * Gets a recieve voice stream.
     *
     * @param int|string $id Either a SSRC or User ID.
     *
     * @deprecated 10.5.0 Use getReceiveStream instead.
     *
     * @return RecieveStream|ReceiveStream|null
     */
    public function getRecieveStream($id)
    {
        return $this->getReceiveStream($id);
    }

    /**
     * Gets a receive voice stream.
     *
     * @param int|string $id Either a SSRC or User ID.
     *
     * @return ReceiveStream|null
     */
    public function getReceiveStream($id)
    {
        if (isset($this->receiveStreams[$id])) {
            return $this->receiveStreams[$id];
        }

        foreach ($this->speakingStatus as $status) {
            if ($status?->user_id == $id) {
                return $this->receiveStreams[$status?->ssrc];
            }
        }

        return null;
    }

    /**
     * Updates speaking status and SSRC→user-ID mapping for a user.
     *
     * Called by the voice gateway when a speaking event is received.
     * Internal use only — public so the WS client can call it without
     * tight coupling via reflection.
     *
     * @internal
     */
    public function updateSpeakingStatus(Speaking $speaking): void
    {
        $this->speakingStatus[$speaking->user_id] = $speaking;
        if ($speaking->ssrc !== null) {
            $this->ssrcToUserId[$speaking->ssrc] = (string) $speaking->user_id;
        }
    }

    /**
     * Handles raw opus data from the UDP server.
     *
     * @param Packet $voicePacket The data from the UDP server.
     */
    public function handleAudioData(Packet $voicePacket): void
    {
        if (! $this->shouldRecord) {
            // If we are not recording, we don't need to handle audio data.
            return;
        }

        $message = $voicePacket?->decryptedAudio ?? null;

        if (! $message || ! $this->speakingStatus->get('ssrc', $voicePacket->getSSRC())) {
            // We don't have a speaking status for this SSRC
            // Probably a "ping" to the udp socket
            // There's no message or the message threw an error inside the decrypt function
            $this->discord->getLogger()->warning('No audio data.', ['voicePacket' => $voicePacket]);

            return;
        }

        $this->emit('raw', [$message, $this]);

        $ss = $this->speakingStatus->get('ssrc', $voicePacket->getSSRC());
        /** @var Process */
        $decoder = $this->voiceDecoders[$voicePacket->getSSRC()] ?? null;

        if (null === $ss) {
            // for some reason we don't have a speaking status
            $this->discord->getLogger()->warning('Unknown SSRC.', ['ssrc' => $voicePacket->getSSRC(), 't' => $voicePacket->getTimestamp()]);

            return;
        }

        if (null === $decoder) {
            // make a decoder
            if (! isset($this->receiveStreams[$ss->ssrc])) {
                $this->receiveStreams[$ss->ssrc] = new ReceiveStream();

                $this->receiveStreams[$ss->ssrc]->on('pcm', fn ($d) => $this->emit('channel-pcm', [$d, $this]));

                $this->receiveStreams[$ss->ssrc]->on('opus', fn ($d) => $this->emit('channel-opus', [$d, $this]));

                // Wire per-user WAV writer when WAV format recording is active.
                if ($this->recordingFormat === RecordingFormat::WAV && $this->recordingOutputPath !== null) {
                    $userId = $this->ssrcToUserId[$ss->ssrc] ?? (string) $ss->ssrc;
                    $path = ($this->recordingOutputPath)($userId);
                    $writer = new WavWriter($path);
                    $writer->open();
                    $this->recordingWriters[$ss->ssrc] = $writer;
                    $this->receiveStreams[$ss->ssrc]->on('pcm', function (string $pcm) use ($writer): void {
                        $writer->write($pcm);
                    });
                }

                // Wire per-user OGG ffmpeg encoder when OGG format recording is active.
                if ($this->recordingFormat === RecordingFormat::OGG && $this->recordingOutputPath !== null) {
                    $userId = $this->ssrcToUserId[$ss->ssrc] ?? (string) $ss->ssrc;
                    $path = ($this->recordingOutputPath)($userId);
                    $ffmpegProcess = new Process("ffmpeg -y -f s16le -ar 48000 -ac 2 -i pipe:0 -c:a libopus \"{$path}\"");
                    $ffmpegProcess->start($this->discord->getLoop());
                    $this->recordingProcesses[$ss->ssrc] = $ffmpegProcess;
                    $this->receiveStreams[$ss->ssrc]->on('pcm', function (string $pcm) use ($ffmpegProcess): void {
                        $ffmpegProcess->stdin->write($pcm);
                    });
                }

                // Wire per-user raw PCM file when PCM format recording is active with an output path.
                if ($this->recordingFormat === RecordingFormat::PCM && $this->recordingOutputPath !== null) {
                    $userId = $this->ssrcToUserId[$ss->ssrc] ?? (string) $ss->ssrc;
                    $path = ($this->recordingOutputPath)($userId);
                    $dir = dirname($path);
                    if (! is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    $handle = fopen($path, 'wb');
                    if ($handle !== false) {
                        $this->recordingPcmHandles[$ss->ssrc] = $handle;
                        $this->receiveStreams[$ss->ssrc]->on('pcm', function (string $pcm) use ($handle): void {
                            fwrite($handle, $pcm);
                        });
                    }
                }
            }

            $this->createDecoder($ss);
            /** @var Process */
            $decoder = $this->voiceDecoders[$ss->ssrc] ?? null;
        }

        if ($decoder->stdin->isWritable() === false) {
            $this->discord->getLogger()->warning('Decoder stdin is not writable.', ['ssrc' => $ss->ssrc]);

            return; // decoder stdin is not writable, cannot write audio data.
            // This should be either restarted or checked if the decoder is still running.
        }

        if (
            empty($voicePacket->decryptedAudio)
            || $voicePacket->decryptedAudio === "\xf8\xff\xfe" // Opus silence frame
            || strlen($voicePacket->decryptedAudio) < 8 // Opus frame is at least 8 bytes
        ) {
            return; // no audio data to write
        }

        if ($this->opusdecoder !== null) {
            if ($this->opusdecoder instanceof OpusFfi) {
                // Use a dedicated persistent decoder per SSRC so each speaker's
                // Opus codec state remains independent.
                if (! isset($this->ffiDecoders[$ss->ssrc])) {
                    $this->ffiDecoders[$ss->ssrc] = new OpusFfi();
                }
                $data = $this->ffiDecoders[$ss->ssrc]->decode($voicePacket->decryptedAudio);
            } else {
                $data = $this->opusdecoder->decode($voicePacket->decryptedAudio);
            }

            if (empty(trim($data))) {
                $this->discord->getLogger()->debug('Received empty audio data.', ['ssrc' => $ss->ssrc]);

                return; // no audio data to write
            }

            // Emit PCM for channel-pcm event (the main recording output path).
            $this->receiveStreams[$ss->ssrc]->writePCM($data);

            // Also feed PCM to the OGG encoder process for channel-opus.
            $decoder->stdin->write($data);
        } else {
            // No FFI Opus decoder — pass raw Opus frames for channel-opus only.
            $this->receiveStreams[$ss->ssrc]->writeOpus($voicePacket->decryptedAudio);
        }
    }

    /**
     * Creates and initializes a decoder process for the given stream session.
     *
     * @param object $ss The stream session object containing information such as SSRC and user ID.
     */
    protected function createDecoder($ss): void
    {
        if (count($this->voiceDecoders) >= self::MAX_DECODERS) {
            $this->discord->getLogger()->warning('Maximum decoder limit reached, refusing new decoder.', ['ssrc' => $ss->ssrc, 'limit' => self::MAX_DECODERS]);

            return;
        }

        $decoder = Ffmpeg::decode((string) $ss->ssrc);
        $decoder->start();

        $decoder->stdout->on('data', function ($data) use ($ss) {
            if (empty($data)) {
                return; // no data to process, should be ignored
            }

            // Emit the decoded opus data
            $this->receiveStreams[$ss->ssrc]->writeOpus($data);
        });

        $decoder->stderr->on('data', function ($data) use ($ss) {
            if (empty($data)) {
                return; // no data to process
            }

            $this->emit("voice.{$ss->ssrc}.stderr", [$data, $this]);
            $this->emit("voice.{$ss->user_id}.stderr", [$data, $this]);
        });

        // Store the decoder
        $this->voiceDecoders[$ss->ssrc] = $decoder;

        // Monitor the process for exit
        $this->monitorProcessExit($decoder, $ss);
    }

    /**
     * Monitor a process for exit and trigger callbacks when it exits.
     *
     * @param Process  $process       The process to monitor
     * @param object   $ss            The speaking status object
     * @param callable $createDecoder Function to create a new decoder if needed
     */
    protected function monitorProcessExit(Process $process, $ss): void
    {
        // Store the process ID
        // $pid = $process->getPid();

        // Check every second if the process is still running
        if ($this->monitorProcessTimer !== null) {
            $this->discord->getLoop()->cancelTimer($this->monitorProcessTimer);
        }

        $this->monitorProcessTimer = $this->discord->getLoop()->addPeriodicTimer(1.0, function () use ($process, $ss) {
            // Check if the process is still running
            if (! $process->isRunning()) {
                // Get the exit code
                $exitCode = $process->getExitCode();

                // Clean up the timer
                $this->discord->getLoop()->cancelTimer($this->monitorProcessTimer);

                // If exit code indicates an error, emit event and recreate decoder
                if ($exitCode > 0) {
                    $this->emit('decoder-error', [$exitCode, null, $ss]);
                    unset($this->voiceDecoders[$ss->ssrc]);
                    $this->createDecoder($ss);
                }
            }
        });
    }
}
