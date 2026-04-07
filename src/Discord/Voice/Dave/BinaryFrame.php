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
    private const MIN_HEADER_SIZE = 3;
    private const HEADER_UNPACK_FORMAT = 'nsequence/Copcode';

    public function __construct(
        public readonly int $sequence,
        public readonly int $opcode,
        public readonly string $payload = ''
    ) {
    }

    public static function fromPayload(string $payload): ?self
    {
        if (strlen($payload) < self::MIN_HEADER_SIZE) {
            return null;
        }

        $header = unpack(self::HEADER_UNPACK_FORMAT, substr($payload, 0, self::MIN_HEADER_SIZE));
        if (! $header) {
            return null;
        }

        return new self(
            $header['sequence'],
            $header['opcode'],
            substr($payload, self::MIN_HEADER_SIZE)
        );
    }

    public function toPayload(): string
    {
        return pack('nC', $this->sequence, $this->opcode).$this->payload;
    }
}
