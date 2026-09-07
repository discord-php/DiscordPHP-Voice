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

use Discord\Exceptions\FileNotFoundException;
use Discord\Voice\Exceptions\Channels\AudioAlreadyPlayingException;
use Discord\Voice\Exceptions\ClientNotReadyException;
use Discord\Voice\Exceptions\Libraries\OutdatedDCAException;
use Discord\Voice\Ogg\Buffer as RealBuffer;
use Discord\Voice\Ogg\OggStream;
use Discord\Voice\Processes\DCA;
use Discord\Voice\Processes\Ffmpeg;
use Discord\WebSockets\Op;
use Discord\WebSockets\VoicePayload;
use React\ChildProcess\Process;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ReadableResourceStream as Stream;
use React\Stream\ReadableStreamInterface;

/**
 * Outbound-audio concern of {@see \Discord\Voice\VoiceClient}: the play* entry
 * points, the Ogg/DCA read loops, and the playback state controls (speaking,
 * volume, bitrate, pause/stop/reset).
 *
 * @since 10.20.0
 */
trait ManagesPlayback
{
    /**
     * Plays a file/url on the voice stream.
     *
     * FFmpeg is used to decode the file, so any format ffmpeg supports is accepted
     * (e.g. WAV, OGG Opus, MP3, FLAC). Raw PCM files (.pcm) are NOT supported here
     * because ffmpeg cannot auto-detect the format — use {@see playPcmFile()} instead.
     *
     * @param string $file     The file/url to play.
     * @param int    $channels Deprecated, Discord only supports 2 channels.
     *
     * @throws FileNotFoundException
     * @throws \RuntimeException
     *
     * @return PromiseInterface
     */
    public function playFile(string $file, int $channels = 2): PromiseInterface
    {
        $deferred = new Deferred();
        $notAValidFile = filter_var($file, FILTER_VALIDATE_URL) === false && ! file_exists($file);

        if (
            $notAValidFile || (! $this->ready) || $this->speaking
        ) {
            if ($notAValidFile) {
                $deferred->reject(new FileNotFoundException("Could not find the file \"{$file}\"."));
            }

            if (! $this->ready) {
                $deferred->reject(new ClientNotReadyException());
            }

            if ($this->speaking) {
                $deferred->reject(new AudioAlreadyPlayingException());
            }

            return $deferred->promise();
        }

        // Validate URL scheme to prevent SSRF via dangerous protocols
        if (filter_var($file, FILTER_VALIDATE_URL) !== false) {
            $scheme = parse_url($file, PHP_URL_SCHEME);
            if ($scheme === null || ! in_array(strtolower($scheme), self::ALLOWED_URL_SCHEMES, true)) {
                $deferred->reject(new \InvalidArgumentException(
                    "URL scheme '{$scheme}' is not allowed. Only ".implode(', ', self::ALLOWED_URL_SCHEMES).' URLs are supported.'
                ));

                return $deferred->promise();
            }

            // Block literal private/reserved/loopback IP addresses and known loopback
            // hostnames to prevent SSRF. Full DNS resolution is explicitly out of scope.
            $host = parse_url($file, PHP_URL_HOST);
            if ($host !== null) {
                if (in_array(strtolower($host), ['localhost'], true)) {
                    $deferred->reject(new \InvalidArgumentException(
                        'Remote playback does not allow private or reserved hostnames.'
                    ));

                    return $deferred->promise();
                }

                $bare = ltrim(rtrim($host, ']'), '['); // strip IPv6 brackets
                if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
                    $isPublic = filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
                    if (! $isPublic) {
                        $deferred->reject(new \InvalidArgumentException(
                            'Remote playback does not allow private or reserved IP addresses.'
                        ));

                        return $deferred->promise();
                    }
                }
            }
        }

        $process = Ffmpeg::encode($file, volume: $this->getDbVolume());
        $process->start();

        return $this->playOggStream($process);
    }

    /**
     * Plays a raw PCM16 stream.
     *
     * @param resource|Stream $stream    The stream to be encoded and sent.
     * @param int             $channels  How many audio channels the PCM16 was encoded with.
     * @param int             $audioRate Audio sampling rate the PCM16 was encoded with.
     *
     * @throws \RuntimeException
     * @throws \InvalidArgumentException Thrown when the stream passed to playRawStream is not a valid resource.
     *
     * @return PromiseInterface
     */
    public function playRawStream($stream, int $channels = 2, int $audioRate = 48000): PromiseInterface
    {
        $deferred = new Deferred();

        if (! $this->ready) {
            $deferred->reject(new \RuntimeException('Voice Client is not ready.'));

            return $deferred->promise();
        }

        if ($this->speaking) {
            $deferred->reject(new \RuntimeException('Audio already playing.'));

            return $deferred->promise();
        }

        if (! is_resource($stream) && ! $stream instanceof Stream) {
            $deferred->reject(new \InvalidArgumentException('The stream passed to playRawStream was not an instance of resource or ReactPHP Stream.'));

            return $deferred->promise();
        }

        if (is_resource($stream)) {
            $stream = new Stream($stream);
        }

        $process = Ffmpeg::encode(volume: $this->getDbVolume(), preArgs: [
            '-f', 's16le',
            '-ac', $channels,
            '-ar', $audioRate,
        ]);
        $process->start();
        $stream->pipe($process->stdin);

        return $this->playOggStream($process);
    }

    /**
     * Plays a raw PCM file on the voice stream.
     *
     * This is a convenience wrapper around {@see playRawStream()} for files saved
     * as raw signed 16-bit little-endian PCM (e.g. recordings produced by
     * {@see record()} with {@see RecordingFormat::PCM}).
     *
     * @param string $path      Absolute or relative path to the raw PCM file.
     * @param int    $channels  Number of audio channels (default: 2 = stereo).
     * @param int    $audioRate Sample rate in Hz (default: 48000).
     *
     * @throws FileNotFoundException if the file does not exist.
     * @throws \RuntimeException     if the file cannot be opened or audio is already playing.
     *
     * @return PromiseInterface
     */
    public function playPcmFile(string $path, int $channels = 2, int $audioRate = 48000): PromiseInterface
    {
        $deferred = new Deferred();

        if (! file_exists($path)) {
            $deferred->reject(new FileNotFoundException("Could not find the file \"{$path}\"."));

            return $deferred->promise();
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $deferred->reject(new \RuntimeException("Could not open file for reading: \"{$path}\"."));

            return $deferred->promise();
        }

        return $this->playRawStream($handle, $channels, $audioRate);
    }

    /**
     * @param resource|Process|Stream $stream The Ogg Opus stream to be sent.
     *
     * @throws \RuntimeException
     * @throws \InvalidArgumentException
     *
     * @return PromiseInterface
     */
    public function playOggStream($stream): PromiseInterface
    {
        $deferred = new Deferred();

        if (! $this->isReady()) {
            $deferred->reject(new \RuntimeException('Voice client is not ready yet.'));

            return $deferred->promise();
        }

        if ($this->speaking) {
            $deferred->reject(new \RuntimeException('Audio already playing.'));

            return $deferred->promise();
        }

        if ($stream instanceof Process) {
            $stream->stderr->on('data', function ($d) {
                if (empty($d)) {
                    return;
                }

                $this->emit('stderr', [$d, $this]);
            });

            $stream = $stream->stdout;
        }

        if (is_resource($stream)) {
            $stream = new Stream($stream);
        }

        if (! ($stream instanceof ReadableStreamInterface)) {
            $deferred->reject(new \InvalidArgumentException('The stream passed to playOggStream was not an instance of resource, ReactPHP Process, ReactPHP Readable Stream'));

            return $deferred->promise();
        }

        $this->buffer = new RealBuffer();
        $stream->on('data', fn ($d) => $this->buffer->write($d));
        $stream->on('end', fn () => $this->buffer->end());

        /** @var OggStream */
        $ogg = null;

        $loops = 0;

        $this->setSpeaking(self::MICROPHONE);

        OggStream::fromBuffer($this->buffer)->then(function (OggStream $os) use ($deferred, &$ogg, &$loops) {
            $ogg = $os;
            $this->startTime = microtime(true) + 0.5;
            $this->readOpusTimer = $this->discord->getLoop()->addTimer(0.5, fn () => $this->readOggOpus($deferred, $ogg, $loops));
        }, function (\Throwable $e) use ($deferred) {
            // Surface the failure on the playback deferred instead of letting the
            // promise rejection bubble out as an unhandled-rejection notice. This
            // covers ffmpeg exiting before producing a valid Ogg header (e.g. bad
            // input file, missing codec, or premature stream close).
            $this->reset();
            $deferred->reject($e);
        });

        return $deferred->promise();
    }

    /**
     * Reads Ogg Opus packets and sends them to the voice server.
     *
     * @param Deferred  $deferred The deferred promise.
     * @param OggStream $ogg      The Ogg stream to read packets from.
     * @param int       &$loops   The number of loops that have been executed.
     */
    protected function readOggOpus(Deferred $deferred, OggStream &$ogg, int &$loops): void
    {
        $this->readOpusTimer = null;

        // Record the target send time for THIS packet BEFORE the async fetch so
        // that the 20 ms window runs concurrently with getPacket(), not after it.
        $targetTime = $this->startTime + (20.0 / 1000.0) * $loops;
        $loops++;

        // If the client is paused, insert silence and wait until the next slot.
        if ($this->paused) {
            $this->udp->insertSilence();
            $delay = max(0.0, $targetTime + (20.0 / 1000.0) - microtime(true));
            $this->readOpusTimer = $this->discord->getLoop()->addTimer($delay, fn () => $this->readOggOpus($deferred, $ogg, $loops));

            return;
        }

        $ogg->getPacket()->then(function ($packet) use (&$loops, &$ogg, $deferred, $targetTime) {
            // EOF for Ogg stream.
            if (null === $packet) {
                $this->reset();
                $deferred->resolve(null);

                return;
            }

            $delay = max(0.0, $targetTime - microtime(true));

            // Use addTimer(0) even when the deadline has already passed to avoid
            // unbounded recursion if several consecutive packets arrive late.
            $this->readOpusTimer = $this->discord->getLoop()->addTimer($delay, function () use ($packet, $deferred, &$ogg, &$loops) {
                $this->udp->sendBuffer($packet);
                $this->readOggOpus($deferred, $ogg, $loops);
            });
        }, function () use ($deferred) {
            $this->reset();
            $deferred->resolve(null);
        });
    }

    /**
     * Plays a DCA stream.
     *
     * @param resource|Process|Stream $stream The DCA stream to be sent.
     *
     * @return PromiseInterface
     * @throws \Exception
     *
     * @deprecated 10.0.0 DCA is now deprecated in DiscordPHP, switch to using
     *                    `playOggStream` with raw Ogg Opus.
     */
    public function playDCAStream($stream): PromiseInterface
    {
        $deferred = new Deferred();

        if (! $this->isReady()) {
            $deferred->reject(new \Exception('Voice client is not ready yet.'));

            return $deferred->promise();
        }

        if ($this->speaking) {
            $deferred->reject(new \Exception('Audio already playing.'));

            return $deferred->promise();
        }

        if ($stream instanceof Process) {
            $stream->stderr->on('data', function ($d) {
                if (empty($d)) {
                    return;
                }

                $this->emit('stderr', [$d, $this]);
            });

            $stream = $stream->stdout;
        }

        if (is_resource($stream)) {
            $stream = new Stream($stream, $this->discord->getLoop());
        }

        if (! ($stream instanceof ReadableStreamInterface)) {
            $deferred->reject(new \Exception('The stream passed to playDCAStream was not an instance of resource, ReactPHP Process, ReactPHP Readable Stream'));

            return $deferred->promise();
        }

        $this->buffer = new RealBuffer($this->discord->getLoop());
        $stream->on('data', fn ($d) => $this->buffer->write($d));

        $this->setSpeaking(self::MICROPHONE);

        // Read magic byte header
        $this->buffer->read(4)->then(function ($mb) {
            if ($mb !== DCA::DCA_VERSION) {
                throw new OutdatedDCAException('The DCA magic byte header was not correct.');
            }

            // Read JSON length
            return $this->buffer->readInt32();
        })->then(function ($jsonLength) {
            if ($jsonLength <= 0 || $jsonLength > 1_000_000) {
                throw new \UnexpectedValueException("Invalid DCA JSON metadata length: {$jsonLength}");
            }

            // Read JSON content
            return $this->buffer->read($jsonLength);
        })->then(function ($metadata) use ($deferred) {
            $metadata = json_decode($metadata, true);

            if (null !== $metadata && isset($metadata['opus']['frame_size'])) {
                $frameSize = (int) ($metadata['opus']['frame_size'] / 48);
                if ($frameSize < 1 || $frameSize > 120) {
                    $frameSize = 20; // safe default: 20ms
                }
                $this->frameSize = $frameSize;
            }

            $this->startTime = microtime(true) + 0.5;
            $this->readOpusTimer = $this->discord->getLoop()->addTimer(0.5, fn () => $this->readDCAOpus($deferred));
        });

        return $deferred->promise();
    }

    /**
     * Reads and processes a single Opus audio frame from a DCA (Discord Compressed Audio) stream.
     *
     * @param Deferred $deferred A promise that will be resolved when the reading process completes or fails.
     */
    protected function readDCAOpus(Deferred $deferred): void
    {
        $this->readOpusTimer = null;

        // If the client is paused, delay by frame size and check again.
        if ($this->paused) {
            $this->udp->insertSilence();
            $this->readOpusTimer = $this->discord->getLoop()->addTimer($this->frameSize / 1000, fn () => $this->readDCAOpus($deferred));

            return;
        }

        // Read opus length
        $this->buffer->readInt16(1000)->then(function ($opusLength) {
            // Read opus data
            return $this->buffer->read($opusLength, null, 1000);
        })->then(function ($opus) use ($deferred) {
            $this->udp->sendBuffer($opus);

            $this->readOpusTimer = $this->discord->getLoop()->addTimer(($this->frameSize - 1) / 1000, fn () => $this->readDCAOpus($deferred));
        }, function () use ($deferred) {
            $this->reset();
            $deferred->resolve(null);
        });
    }

    /**
     * Resets the voice client.
     */
    protected function reset(): void
    {
        if ($this->readOpusTimer) {
            $this->discord->getLoop()->cancelTimer($this->readOpusTimer);
            $this->readOpusTimer = null;
        }

        $this->setSpeaking(self::NOT_SPEAKING);
        $this->streamTime = 0;
        $this->startTime = 0;
        $this->paused = false;
        $this->silenceRemaining = 5;
    }

    /**
     * Sets the speaking value of the client.
     *
     * @param int $speaking Whether the client is speaking or not.
     *
     * @throws \RuntimeException
     */
    public function setSpeaking(int $speaking = self::MICROPHONE): void
    {
        if ($this->speaking === $speaking) {
            return;
        }

        if (! $this->ready) {
            throw new \RuntimeException('Voice Client is not ready.');
        }

        $this->udp->ws->send(VoicePayload::new(
            Op::VOICE_SPEAKING,
            [
                'speaking' => $speaking,
                'delay' => 0,
                'ssrc' => $this->ssrc,
            ],
        ));

        $this->speaking = $speaking;
    }

    /**
     * Sets the bitrate.
     *
     * @param int $bitrate The bitrate to set.
     *
     * @throws \DomainException
     * @throws \RuntimeException
     */
    public function setBitrate(int $bitrate): void
    {
        if ($bitrate < 8000 || $bitrate > 384000) {
            throw new \DomainException("{$bitrate} is not a valid option. The bitrate must be between 8,000 bps and 384,000 bps.");
        }

        /*if ($this->speaking) {
            throw new \RuntimeException('Cannot change bitrate while playing.');
        }*/

        $this->bitrate = $bitrate;
    }

    /**
     * Sets the volume.
     *
     * @param int $volume The volume to set.
     *
     * @throws \DomainException
     * @throws \RuntimeException
     */
    public function setVolume(int $volume): void
    {
        if ($volume < 0 || $volume > 100) {
            throw new \DomainException("{$volume}% is not a valid option. The bitrate must be between 0% and 100%.");
        }

        if ($this->speaking) {
            throw new \RuntimeException('Cannot change volume while playing.');
        }

        $this->volume = $volume;
    }

    /**
     * Sets the audio application.
     *
     * @param string $app The audio application to set.
     *
     * @throws \DomainException
     * @throws \RuntimeException
     */
    public function setAudioApplication(string $app): void
    {
        $legal = ['voip', 'audio', 'lowdelay'];

        if (! in_array($app, $legal)) {
            throw new \DomainException("{$app} is not a valid option. Valid options are: ".implode(', ', $legal));
        }

        if ($this->speaking) {
            throw new \RuntimeException('Cannot change audio application while playing.');
        }

        $this->audioApplication = $app;
    }

    /**
     * Pauses the current sound.
     *
     * @throws \RuntimeException
     */
    public function pause(): void
    {
        if (! $this->speaking) {
            throw new \RuntimeException('Audio must be playing to pause it.');
        }

        if ($this->paused) {
            throw new \RuntimeException('Audio is already paused.');
        }

        $this->paused = true;
        $this->udp->refreshSilenceFrames();
    }

    /**
     * Unpauses the current sound.
     *
     * @throws \RuntimeException
     */
    public function unpause(): void
    {
        if (! $this->speaking) {
            throw new \RuntimeException('Audio must be playing to unpause it.');
        }

        if (! $this->paused) {
            throw new \RuntimeException('Audio is already playing.');
        }

        $this->paused = false;
        $this->timestamp = (int) round(microtime(true) * 1000);
    }

    /**
     * Stops the current sound.
     *
     * @throws \RuntimeException
     */
    public function stop(): void
    {
        if (! $this->speaking) {
            throw new \RuntimeException('Audio must be playing to stop it.');
        }

        if (isset($this->buffer)) {
            $this->buffer->end();
        }
        $this->udp->insertSilence();
        $this->reset();
    }

    /**
     * The current playback volume expressed in decibels.
     *
     * Maps the 0-100 linear `volume` to roughly -100 dB (mute) up to 0 dB (full).
     */
    public function getDbVolume(): float|int
    {
        return match ($this->volume) {
            0 => -100,
            100 => 0,
            default => -40 + ($this->volume / 100) * 40,
        };
    }
}
