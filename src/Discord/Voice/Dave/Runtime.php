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

use FFI;

final class Runtime
{
    private const DEFAULT_LIBRARY_PATH = 'libdave.so';

    private static bool $loaded = false;

    private static ?FFI $ffi = null;

    private static ?string $lastLoadError = null;

    public static function isAvailable(): bool
    {
        self::load();

        return self::$ffi instanceof FFI;
    }

    public static function maxProtocolVersion(): int
    {
        self::load();

        if (! self::$ffi instanceof FFI) {
            return 0;
        }

        try {
            return (int) self::$ffi->daveMaxSupportedProtocolVersion();
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return 0;
        }
    }

    public static function getLastLoadError(): ?string
    {
        return self::$lastLoadError;
    }

    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;

        if (! extension_loaded('ffi')) {
            self::$lastLoadError = 'ext-ffi is not loaded.';

            return;
        }

        $libraryPath = getenv('DISCORDPHP_DAVE_LIBRARY');
        if ($libraryPath === false || $libraryPath === '') {
            $libraryPath = self::DEFAULT_LIBRARY_PATH;
        }

        try {
            self::$ffi = FFI::cdef(
                '
                unsigned short daveMaxSupportedProtocolVersion(void);
            ',
                $libraryPath
            );
        } catch (\Throwable $e) {
            self::$ffi = null;
            self::$lastLoadError = $e->getMessage();
        }
    }
}
