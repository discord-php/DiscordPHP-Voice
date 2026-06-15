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

use FFI;

/**
 * Handles the decoding of Opus audio data using FFI (Foreign Function Interface).
 *
 * @todo
 *
 * @property FFI   $ffi
 * @method   int   opus_packet_get_nb_frames(mixed $packet, int $len)
 * @method   int   opus_packet_get_samples_per_frame(mixed $data, int $Fs)
 * @method   mixed opus_decoder_create(int $Fs, int $channels, mixed $error)
 * @method   int   opus_decode(mixed $st, mixed $data, int $len, mixed $pcm, int $frame_size, int $decode_fec)
 * @method   void  opus_decoder_destroy(mixed $st)
 */
class OpusFfi implements OpusDecoderInterface
{
    protected FFI $ffi;

    /** @var array<string, mixed> Persistent decoder handles keyed by "channels:rate". */
    private array $decoderHandles = [];

    public function __destruct()
    {
        foreach ($this->decoderHandles as $decoder) {
            $this->opus_decoder_destroy($decoder);
        }
        $this->decoderHandles = [];
    }

    public function __construct()
    {
        // Load libopus and define needed functions/types
        $this->ffi = FFI::cdef('
        typedef struct OpusDecoder OpusDecoder;
        typedef short opus_int16;
        typedef int opus_int32;

        int opus_packet_get_nb_frames(const unsigned char packet[], opus_int32 len);
        int opus_packet_get_samples_per_frame(const unsigned char * data, opus_int32 Fs);

        OpusDecoder *opus_decoder_create(opus_int32 Fs, int channels, int *error);
        int opus_decode(OpusDecoder *st, const unsigned char *data, opus_int32 len, opus_int16 *pcm, int frame_size, int decode_fec);
        void opus_decoder_destroy(OpusDecoder *st);
        ', 'libopus.so.0');
    }

    public static function new(): self
    {
        return new self();
    }

    public static function isAvailable(): bool
    {
        try {
            new self();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Creates a FFI instance (code in C) to decode Opus audio data.
     * By using the libopus library, this function decodes Opus-encoded audio data
     * into PCM samples.
     *
     * @param string|mixed $data The Opus-encoded audio data to decode.
     *
     * @return string The decoded PCM audio data as a string/binary.
     */
    public function decode($data, int $channels = 2, int $audioRate = 48000): string
    {
        $dataLength = strlen($data);
        if ($dataLength <= 0) {
            return '';
        }

        $dataBuffer = $this->ffi->new("const unsigned char[$dataLength]", false);
        FFI::memcpy($dataBuffer, $data, $dataLength);

        $frames = $this->opus_packet_get_nb_frames($dataBuffer, $dataLength);
        $samplesPerFrame = $this->opus_packet_get_samples_per_frame($dataBuffer, $audioRate);
        $frameSize = $frames * $samplesPerFrame;

        // Lazily create and persist the decoder for this (channels, rate) pair so that
        // inter-frame codec state (pitch predictors, loss concealment context) is preserved
        // across packets. Destroying and recreating the decoder per frame causes buzzing.
        $key = "{$channels}:{$audioRate}";
        if (! isset($this->decoderHandles[$key])) {
            $error = $this->ffi->new('int');
            $decoder = $this->opus_decoder_create($audioRate, $channels, FFI::addr($error));
            if ($error->cdata !== 0 || $decoder === null) {
                return '';
            }
            $this->decoderHandles[$key] = $decoder;
        }

        $decoder = $this->decoderHandles[$key];

        // Buffer sized for exactly frameSize * channels int16 samples.
        $bufferSize = $frameSize * $channels;
        $pcm = $this->ffi->new("opus_int16[{$bufferSize}]", false);

        $ret = $this->opus_decode($decoder, $dataBuffer, $dataLength, $pcm, $frameSize, 0);

        if ($ret < 0) {
            return '';
        }

        // 2 bytes per int16 sample
        return FFI::string($pcm, $ret * $channels * 2);
    }

    /**
     * Magic method to redirect method calls to the FFI instance.
     *
     * @param  string $name
     * @param  array  $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments)
    {
        return $this->ffi->$name(...$arguments);
    }
}
