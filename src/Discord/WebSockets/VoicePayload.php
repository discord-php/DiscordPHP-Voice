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

namespace Discord\WebSockets;

/**
 * Represents a Gateway event payload with a voice token.
 *
 * Gateway event payloads have a common structure, but the contents of the associated data (d) varies between the different events.
 *
 * @link https://discord.com/developers/docs/topics/voice-connections#retrieving-voice-server-information-example-voice-server-update-payload
 *
 * @property token
 */
class VoicePayload extends Payload
{
    /** @var string|null */
    protected $token;

    /**
     * @param int         $op    Gateway opcode.
     * @param mixed       $d     Event data payload.
     * @param int|null    $s     Sequence number.
     * @param string|null $t     Event name.
     * @param string|null $token Voice connection token, merged into `d.token` on serialisation.
     */
    public function __construct(int $op, $d = null, ?int $s = null, ?string $t = null, ?string $token = null)
    {
        $this->op = $op;
        $this->d = $d;
        $this->s = $s;
        $this->t = $t;
        $this->token = $token;
    }

    /**
     * @inheritDoc
     */
    public static function new(
        int $op,
        $d = null,
        ?int $s = null,
        ?string $t = null,
        ?string $token = null
    ): self {
        return new self($op, $d, $s, $t, $token);
    }

    /**
     * Sets the voice token merged into `d.token` when the payload is serialised.
     *
     * @return $this
     */
    public function setToken(?string $token = null): self
    {
        $this->token = $token;

        return $this;
    }

    /** The voice token, or null when unset. */
    public function getToken(): ?string
    {
        return $this->token ?? null;
    }

    /**
     * @inheritDoc
     *
     * Adds the voice `token` under `d` when one is set.
     */
    public function jsonSerialize(): array
    {
        $data = parent::jsonSerialize();

        if (isset($this->token)) {
            $data['d']['token'] = $this->token;
        }

        return $data;
    }

    /**
     * @inheritDoc
     *
     * Redacts the voice token.
     */
    public function __debugInfo()
    {
        $array = parent::__debugInfo();

        if (isset($array['token'])) {
            $array['token'] = 'xxxxx';
        }

        return $array;
    }
}
