<?php

/*
 * This file is a part of the DiscordPHP project.
 *
 * Copyright (c) 2015-2022 David Cole <david.cole1340@gmail.com>
 * Copyright (c) 2020-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Discord\Voice\Exceptions\Channels;

/**
 * Thrown when the Voice Client is already playing audio.
 *
 * @since 10.0.0
 */
final class AudioAlreadyPlayingException extends \RuntimeException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? 'Audio is already playing.');
    }
}
