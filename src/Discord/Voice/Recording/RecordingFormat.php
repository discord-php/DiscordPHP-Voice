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

/**
 * Represents the output format for voice recording.
 */
enum RecordingFormat: string
{
    /** Raw signed 16-bit little-endian PCM (no file writing). */
    case PCM = 'pcm';

    /** Standard PCM WAV container (pure PHP, no external dependencies). */
    case WAV = 'wav';

    /** OGG Opus container (requires ffmpeg). */
    case OGG = 'ogg';

    /** Returns the file extension for this format. */
    public function extension(): string
    {
        return $this->value;
    }

    /** Returns a human-readable label for this format. */
    public function label(): string
    {
        return match ($this) {
            self::PCM => 'Raw PCM',
            self::WAV => 'WAV Audio',
            self::OGG => 'OGG Opus',
        };
    }

    /** Returns true if this format requires ffmpeg to encode. */
    public function requiresFfmpeg(): bool
    {
        return $this === self::OGG;
    }
}
