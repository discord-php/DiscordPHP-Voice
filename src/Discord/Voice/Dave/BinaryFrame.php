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

final class BinaryFrame
{
    public function __construct(
        public readonly int $sequence,
        public readonly int $opcode,
        public readonly string $payload = ''
    ) {
    }

    public static function fromPayload(string $payload): ?self
    {
        if (strlen($payload) < 3) {
            return null;
        }

        $header = unpack('nsequence/Copcode', substr($payload, 0, 3));
        if (! $header) {
            return null;
        }

        return new self(
            $header['sequence'],
            $header['opcode'],
            substr($payload, 3)
        );
    }

    public function toPayload(): string
    {
        return pack('nC', $this->sequence, $this->opcode).$this->payload;
    }
}
