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

namespace Discord\Voice\Client;

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
