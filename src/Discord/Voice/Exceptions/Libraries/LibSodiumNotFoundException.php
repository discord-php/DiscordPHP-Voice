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

namespace Discord\Voice\Exceptions\Libraries;

use Discord\Voice\Exceptions\VoiceException;

/**
 * Thrown when libsodium or libsodium-php cannot be found.
 *
 * @since 3.2.1
 */
class LibSodiumNotFoundException extends \Exception implements VoiceException
{
}
