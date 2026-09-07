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

namespace Discord\Voice\Receive;

use Discord\Discord;
use Discord\Voice\ReceiveStream;
use Discord\Voice\Speaking;
use Discord\Voice\VoiceClient;
use React\ChildProcess\Process;

/**
 * @since 10.19.0
 */
final class User
{
    /**
     * @param Discord       $discord     The Discord client.
     * @param VoiceClient   $voiceClient The voice connection this user is heard on.
     * @param int           $ssrc        The RTP SSRC assigned to this user's audio.
     * @param Process       $decoder     The per-user Opus-to-PCM decoder process.
     * @param ReceiveStream $stream      The stream emitting this user's decoded audio.
     * @param Speaking|null $part        The last received speaking payload, if any.
     */
    public function __construct(
        protected Discord $discord,
        protected VoiceClient $voiceClient,
        protected int $ssrc,
        protected Process $decoder,
        protected ReceiveStream $stream,
        protected ?Speaking $part = null,
    ) {
    }
}
