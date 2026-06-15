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

namespace Discord\Voice\Dave;

final class TransitionPayload
{
    public function __construct(
        public readonly int $transitionId,
        public readonly string $payload,
    ) {
    }

    /**
     * Parse a raw binary string where the first 2 bytes are a big-endian uint16 transition_id
     * and the remainder is the MLS payload.
     *
     * Returns null if the input is less than 2 bytes.
     */
    public static function parse(string $raw): ?self
    {
        if (strlen($raw) < 2) {
            return null;
        }

        $unpacked = unpack('ntransition_id', $raw);
        if ($unpacked === false) {
            return null;
        }

        return new self(
            transitionId: $unpacked['transition_id'],
            payload: substr($raw, 2),
        );
    }

    /**
     * Returns true when this is a "zero transition" — executed locally, no gateway round-trip.
     */
    public function isZeroTransition(): bool
    {
        return $this->transitionId === 0;
    }
}
