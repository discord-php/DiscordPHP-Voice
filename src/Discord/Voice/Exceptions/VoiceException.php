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

namespace Discord\Voice\Exceptions;

/**
 * Marker interface for all DiscordPHP-Voice exceptions.
 *
 * Consumers can catch any library-thrown exception with a single catch block:
 *
 * ```php
 * try {
 *     $discord->voice->join($channel);
 * } catch (VoiceException $e) {
 *     // handles all voice library errors
 * }
 * ```
 *
 * @since 10.19.0
 */
interface VoiceException extends \Throwable
{
}
