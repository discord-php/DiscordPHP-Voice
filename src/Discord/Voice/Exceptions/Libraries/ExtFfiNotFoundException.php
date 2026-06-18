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
 * Thrown when the PHP FFI extension (ext-ffi) is unavailable or disabled.
 *
 * The DAVE native library requires PHP's FFI to interface with native code.
 *
 * @since 8.1.0
 */
class ExtFfiNotFoundException extends \Exception implements VoiceException
{
    /**
     * Creates an instance from a message string or the runtime state.
     *
     * When {@param $message} is provided it is used verbatim.
     * When omitted, a helpful message is composed with a reference link
     * and any available runtime load error is appended.
     */
    public static function fromRuntimeError(string $message = ''): self
    {
        if ($message !== '') {
            return new self($message);
        }

        $message = "The PHP FFI extension (ext-ffi) is required but could not be found or is disabled.\n"
            .'See https://www.php.net/manual/en/ffi.setup.php for instructions on enabling FFI in your PHP installation.';

        $loadError = DaveRuntime::getLastLoadError();
        if (is_string($loadError) && $loadError !== '') {
            $message .= "\nLoad error: {$loadError}";
        }

        return new self($message);
    }
}
