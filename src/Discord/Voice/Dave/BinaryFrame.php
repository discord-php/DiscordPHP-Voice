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
    private const CLIENT_MIN_HEADER_SIZE = 1;
    private const SERVER_MIN_HEADER_SIZE = 3;
    private const SERVER_HEADER_UNPACK_FORMAT = 'nsequence/Copcode';
    private const CLIENT_HEADER_UNPACK_FORMAT = 'Copcode';

    public function __construct(
        public readonly ?int $sequence,
        public readonly int $opcode,
        public readonly string $payload = ''
    ) {
    }

    public static function fromServerPayload(string $payload): ?self
    {
        if (strlen($payload) < self::SERVER_MIN_HEADER_SIZE) {
            return null;
        }

        $header = unpack(self::SERVER_HEADER_UNPACK_FORMAT, substr($payload, 0, self::SERVER_MIN_HEADER_SIZE));
        if (! $header) {
            return null;
        }

        return new self(
            $header['sequence'],
            $header['opcode'],
            substr($payload, self::SERVER_MIN_HEADER_SIZE)
        );
    }

    /**
     * @deprecated Use {@see self::fromServerPayload()} instead.
     */
    public static function fromPayload(string $payload): ?self
    {
        return self::fromServerPayload($payload);
    }

    public static function fromClientPayload(string $payload): ?self
    {
        if (strlen($payload) < self::CLIENT_MIN_HEADER_SIZE) {
            return null;
        }

        $header = unpack(self::CLIENT_HEADER_UNPACK_FORMAT, substr($payload, 0, self::CLIENT_MIN_HEADER_SIZE));
        if (! $header) {
            return null;
        }

        return new self(
            null,
            $header['opcode'],
            substr($payload, self::CLIENT_MIN_HEADER_SIZE)
        );
    }

    public function toServerPayload(): string
    {
        if ($this->sequence === null) {
            throw new \RuntimeException('Server DAVE binary frames require a sequence number.');
        }

        return pack('nC', $this->sequence, $this->opcode).$this->payload;
    }

    /**
     * @deprecated Use {@see self::toServerPayload()} instead.
     */
    public function toPayload(): string
    {
        return $this->toServerPayload();
    }

    public function toClientPayload(): string
    {
        return pack('C', $this->opcode).$this->payload;
    }
}
