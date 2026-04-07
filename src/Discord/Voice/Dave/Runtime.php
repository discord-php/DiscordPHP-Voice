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
    private const DAVE_FFI_DEFINITIONS = <<<'CDEF'
unsigned short daveMaxSupportedProtocolVersion(void);
CDEF;

    private static bool $loaded = false;

    private static ?FFI $ffi = null;

    private static ?string $lastLoadError = null;

    /**
     * @var null|callable(string, int): ?string
     */
    private static $frameEncryptor = null;

    /**
     * @var null|callable(string, int): false|string|null
     */
    private static $frameDecryptor = null;

    /**
     * @var null|callable(string, int): ?string
     */
    private static $mlsCommitWelcomeBuilder = null;

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

    /**
     * @param null|callable(string, int): ?string           $frameEncryptor
     * @param null|callable(string, int): false|string|null $frameDecryptor
     * @param null|callable(string, int): ?string           $mlsCommitWelcomeBuilder
     */
    public static function configureCallbacks(
        ?callable $frameEncryptor = null,
        ?callable $frameDecryptor = null,
        ?callable $mlsCommitWelcomeBuilder = null
    ): void {
        self::$frameEncryptor = $frameEncryptor;
        self::$frameDecryptor = $frameDecryptor;
        self::$mlsCommitWelcomeBuilder = $mlsCommitWelcomeBuilder;
    }

    public static function encryptMediaFrame(string $frame, int $protocolVersion): ?string
    {
        if ($protocolVersion <= 0) {
            return $frame;
        }

        if (is_callable(self::$frameEncryptor)) {
            return (self::$frameEncryptor)($frame, $protocolVersion);
        }

        if (! self::isAvailable()) {
            return null;
        }

        // Not yet wired to the published libdave frame API in this package.
        return null;
    }

    public static function decryptMediaFrame(string $frame, int $protocolVersion): string|false|null
    {
        if ($protocolVersion <= 0) {
            return $frame;
        }

        if (is_callable(self::$frameDecryptor)) {
            return (self::$frameDecryptor)($frame, $protocolVersion);
        }

        if (! self::isAvailable()) {
            return null;
        }

        // Not yet wired to the published libdave frame API in this package.
        return null;
    }

    public static function buildMlsCommitWelcome(string $proposalsPayload, int $protocolVersion): ?string
    {
        if ($protocolVersion <= 0) {
            return null;
        }

        if (is_callable(self::$mlsCommitWelcomeBuilder)) {
            return (self::$mlsCommitWelcomeBuilder)($proposalsPayload, $protocolVersion);
        }

        if (! self::isAvailable()) {
            return null;
        }

        // Not yet wired to the published libdave MLS APIs in this package.
        return null;
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
                self::DAVE_FFI_DEFINITIONS,
                $libraryPath
            );
        } catch (\Throwable $e) {
            self::$ffi = null;
            self::$lastLoadError = $e->getMessage();
        }
    }
}
