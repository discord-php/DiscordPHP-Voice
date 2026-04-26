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
use Discord\Voice\Exceptions\VoiceException;

/**
 * Thrown when libdave cannot be loaded and the voice connection cannot proceed.
 *
 * Discord requires the DAVE E2EE protocol for all voice/video connections since March 1st, 2026.
 *
 * @see https://discord.com/developers/docs/change-log#future-deprecation-and-discontinuation-of-non-e2ee-voice
 *
 * @since 8.1.0
 */
class LibDaveNotFoundException extends \Exception implements VoiceException
{
    /**
     * Creates an instance from a message string.
     *
     * When {@param $message} is provided it is used verbatim; this is the path
     * taken by {@see Runtime::resolveDefaultLibraryPath()} when the library
     * file cannot be found on disk.
     *
     * When {@param $message} is omitted (empty string), a descriptive message
     * is built that includes the Discord announcement link, installation
     * instructions, and the runtime load error (if one is available from
     * {@see DaveRuntime::getLastLoadError()}). This is the path taken by
     * callers that check {@see DaveRuntime::isAvailable()} after a failed load.
     *
     * @throws LibDaveNotFoundException
     */
    public static function fromRuntimeError(string $message = ''): self
    {
        if ($message !== '') {
            return new self($message);
        }

        $message = "libdave is required but could not be loaded. Discord has required the DAVE E2EE protocol for all voice and video connections since March 1st, 2026.\n"
            ."Discord announcement: https://discord.com/developers/docs/change-log#future-deprecation-and-discontinuation-of-non-e2ee-voice\n"
            .'To install libdave, run: bash scripts/setup-libdave.sh from the project root, or see https://github.com/discord/libdave for manual installation.';

        $loadError = DaveRuntime::getLastLoadError();
        if (is_string($loadError) && $loadError !== '') {
            $message .= "\nLoad error: {$loadError}";
        }

        return new self($message);
    }
}
