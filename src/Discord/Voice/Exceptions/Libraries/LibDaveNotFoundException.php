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

use Discord\Voice\Dave\Runtime as DaveRuntime;

/**
 * Thrown when libdave cannot be loaded and the voice connection cannot proceed.
 *
 * Discord requires the DAVE E2EE protocol for all voice/video connections since March 1st, 2026.
 *
 * @see https://discord.com/developers/docs/change-log#future-deprecation-and-discontinuation-of-non-e2ee-voice
 *
 * @since 8.1.0
 */
class LibDaveNotFoundException extends \Exception
{
    /**
     * Creates an instance with a descriptive message that includes the Discord
     * announcement link, installation instructions, and the runtime load error
     * (if one is available from {@see DaveRuntime::getLastLoadError()}).
     */
    public static function fromRuntimeError(): self
    {
        $message = "libdave is required but could not be loaded. Discord has required the DAVE E2EE protocol for all voice and video connections since March 1st, 2026.\n"
            ."Discord announcement: https://discord.com/developers/docs/change-log#future-deprecation-and-discontinuation-of-non-e2ee-voice\n"
            .'To install libdave, run: bash scripts/setup-libdave.sh from the project root, or see https://github.com/discord/libdave for manual installation.';

        $loadError = DaveRuntime::getLastLoadError();
        if ($loadError !== '') {
            $message .= "\nLoad error: {$loadError}";
        }

        return new self($message);
    }
}
