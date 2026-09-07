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

/**
 * Interface for Opus Decoder implementations.
 */
interface OpusDecoderInterface
{
    /**
     * Decodes one Opus packet to signed 16-bit little-endian PCM.
     *
     * @param string|mixed $data      The Opus-encoded packet.
     * @param int          $channels  Output channel count.
     * @param int          $audioRate Output sample rate in Hz.
     *
     * @return string The decoded PCM samples as a binary string, or an empty string on failure.
     */
    public function decode($data, int $channels = 2, int $audioRate = 48000): string;
}
