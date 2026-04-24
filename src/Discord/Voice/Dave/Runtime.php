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
    /**
     * Platform-specific typedefs prepended to FFI definitions.
     * PHP FFI::cdef() does not process C headers, so we provide these explicitly.
     */
    private const FFI_TYPEDEF_PRELUDE = <<<'CDEF'
typedef unsigned char uint8_t;
typedef unsigned short uint16_t;
typedef unsigned int uint32_t;
typedef unsigned long size_t;
typedef unsigned long long uint64_t;
typedef _Bool bool;
CDEF;

    public const MEDIA_TYPE_AUDIO = 0;
    public const CODEC_OPUS = 1;

    protected const RESULT_SUCCESS = 0;

    protected static bool $loaded = false;

    protected static ?FFI $ffi = null;

    protected static ?string $lastLoadError = null;

    private static ?string $lastDestroyError = null;

    /**
     * @var null|callable(string, int): ?string
     */
    protected static $frameEncryptor = null;

    /**
     * @var null|callable(string, int): false|string|null
     */
    protected static $frameDecryptor = null;

    /**
     * @var null|callable(string, int): ?string
     */
    protected static $mlsCommitWelcomeBuilder = null;

    /**
     * @var null|callable(?SessionHandle, string): ?array
     */
    protected static $processCommitCallback = null;

    /**
     * @var null|callable(?SessionHandle, string, array): bool
     */
    protected static $processWelcomeCallback = null;

    /**
     * @var null|callable(?string): ?SessionHandle
     */
    protected static $createSessionCallback = null;

    protected static ?bool $availabilityOverride = null;

    public static function isAvailable(): bool
    {
        if (self::$availabilityOverride !== null) {
            return self::$availabilityOverride;
        }

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
            return (int) self::call(self::$ffi, 'daveMaxSupportedProtocolVersion');
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return 0;
        }
    }

    public static function getLastLoadError(): ?string
    {
        return self::$lastLoadError;
    }

    public static function getLastDestroyError(): ?string
    {
        return self::$lastDestroyError;
    }

    public static function reset(): void
    {
        self::$loaded = false;
        self::$ffi = null;
        self::$lastLoadError = null;
        self::$lastDestroyError = null;
        self::$frameEncryptor = null;
        self::$frameDecryptor = null;
        self::$mlsCommitWelcomeBuilder = null;
        self::$processCommitCallback = null;
        self::$processWelcomeCallback = null;
        self::$createSessionCallback = null;
        self::$availabilityOverride = null;
    }

    /**
     * @param null|callable(string, int): ?string                $frameEncryptor
     * @param null|callable(string, int): false|string|null      $frameDecryptor
     * @param null|callable(string, int): ?string                $mlsCommitWelcomeBuilder
     * @param null|callable(?SessionHandle, string): ?array      $processCommitCallback
     * @param null|callable(?SessionHandle, string, array): bool $processWelcomeCallback
     * @param null|callable(?string): ?SessionHandle             $createSessionCallback
     */
    public static function configureCallbacks(
        ?callable $frameEncryptor = null,
        ?callable $frameDecryptor = null,
        ?callable $mlsCommitWelcomeBuilder = null,
        ?callable $processCommitCallback = null,
        ?callable $processWelcomeCallback = null,
        ?callable $createSessionCallback = null,
        ?bool $availabilityOverride = null
    ): void {
        self::$frameEncryptor = $frameEncryptor;
        self::$frameDecryptor = $frameDecryptor;
        self::$mlsCommitWelcomeBuilder = $mlsCommitWelcomeBuilder;
        self::$processCommitCallback = $processCommitCallback;
        self::$processWelcomeCallback = $processWelcomeCallback;
        self::$createSessionCallback = $createSessionCallback;
        self::$availabilityOverride = $availabilityOverride;
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

        return null;
    }

    public static function createSession(?string $authSessionId = null): ?SessionHandle
    {
        if (is_callable(self::$createSessionCallback)) {
            return (self::$createSessionCallback)($authSessionId);
        }

        $ffi = self::ffi();
        if (! $ffi instanceof FFI) {
            return null;
        }

        try {
            $session = self::call($ffi, 'daveSessionCreate', null, $authSessionId, null, null);

            return FFI::isNull($session) ? null : new SessionHandle($session);
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return null;
        }
    }

    public static function initializeSession(SessionHandle $session, int $version, int $groupId, string $selfUserId): bool
    {
        $ffi = self::ffi();
        if (! $ffi instanceof FFI) {
            return false;
        }

        try {
            self::call($ffi, 'daveSessionInit', $session->raw(), $version, $groupId, $selfUserId);

            return true;
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return false;
        }
    }

    public static function resetSession(SessionHandle $session): bool
    {
        $ffi = self::ffi();
        if (! $ffi instanceof FFI) {
            return false;
        }

        try {
            self::call($ffi, 'daveSessionReset', $session->raw());

            return true;
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return false;
        }
    }

    public static function setSessionProtocolVersion(SessionHandle $session, int $version): bool
    {
        $ffi = self::ffi();
        if (! $ffi instanceof FFI) {
            return false;
        }

        try {
            self::call($ffi, 'daveSessionSetProtocolVersion', $session->raw(), $version);

            return true;
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return false;
        }
    }

    public static function getSessionProtocolVersion(SessionHandle $session): int
    {
        $ffi = self::ffi();
        if (! $ffi instanceof FFI) {
            return 0;
        }

        try {
            return (int) self::call($ffi, 'daveSessionGetProtocolVersion', $session->raw());
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return 0;
        }
    }

    public static function setExternalSender(SessionHandle $session, string $externalSender): bool
    {
        $ffi = self::ffi();
        if (! $ffi instanceof FFI) {
            return false;
        }

        [$pointer, $length, $buffer] = self::makeBytePointer($externalSender);
        if ($pointer === null) {
            return false;
        }

        try {
            self::call($ffi, 'daveSessionSetExternalSender', $session->raw(), $pointer, $length);

            return true;
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return false;
        }
    }

    /**
     * @param list<string> $recognizedUserIds
     */
    public static function buildMlsCommitWelcomeWithSession(SessionHandle $session, string $proposalsPayload, array $recognizedUserIds): ?string
    {
        $ffi = self::ffi();
        if (! $ffi instanceof FFI) {
            return null;
        }

        [$proposalPointer, $proposalLength, $proposalBuffer] = self::makeBytePointer($proposalsPayload);
        if ($proposalPointer === null) {
            return null;
        }
        [$recognizedPointers, $recognizedBuffers] = self::makeStringPointerArray($recognizedUserIds);
        [$buffer, $length] = self::makeOutputByteBuffer();

        // libdave's ProcessProposals signature is `const char* const*` —
        // we have to pass the address of the first pointer so it sees a
        // contiguous array, not a pointer to a single FFI pointer object.
        $recognizedPointerArg = $recognizedPointers !== null && count($recognizedUserIds) > 0
            ? FFI::addr($recognizedPointers[0])
            : null;

        try {
            self::call(
                $ffi,
                'daveSessionProcessProposals',
                $session->raw(),
                $proposalPointer,
                $proposalLength,
                $recognizedPointerArg,
                count($recognizedUserIds),
                FFI::addr($buffer[0]),
                FFI::addr($length[0])
            );

            return self::takeOutputBytes($buffer, $length);
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return null;
        }
    }

    public static function processCommit(SessionHandle $session, string $commit): ?array
    {
        if (is_callable(self::$processCommitCallback)) {
            return (self::$processCommitCallback)($session, $commit);
        }

        $ffi = self::ffi();
        if (! $ffi instanceof FFI) {
            return null;
        }

        [$commitPointer, $commitLength, $commitBuffer] = self::makeBytePointer($commit);
        if ($commitPointer === null) {
            return null;
        }

        try {
            $result = self::call($ffi, 'daveSessionProcessCommit', $session->raw(), $commitPointer, $commitLength);
            if (FFI::isNull($result)) {
                return null;
            }

            try {
                return [
                    'failed' => (bool) self::call($ffi, 'daveCommitResultIsFailed', $result),
                    'ignored' => (bool) self::call($ffi, 'daveCommitResultIsIgnored', $result),
                ];
            } finally {
                self::call($ffi, 'daveCommitResultDestroy', $result);
            }
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return null;
        }
    }

    /**
     * @param list<string> $recognizedUserIds
     */
    public static function processWelcome(SessionHandle $session, string $welcome, array $recognizedUserIds): bool
    {
        if (is_callable(self::$processWelcomeCallback)) {
            return (self::$processWelcomeCallback)($session, $welcome, $recognizedUserIds);
        }

        $ffi = self::ffi();
        if (! $ffi instanceof FFI) {
            return false;
        }

        [$welcomePointer, $welcomeLength, $welcomeBuffer] = self::makeBytePointer($welcome);
        if ($welcomePointer === null) {
            return false;
        }
        [$recognizedPointers, $recognizedBuffers] = self::makeStringPointerArray($recognizedUserIds);

        try {
            $result = self::call(
                $ffi,
                'daveSessionProcessWelcome',
                $session->raw(),
                $welcomePointer,
                $welcomeLength,
                $recognizedPointers,
                count($recognizedUserIds)
            );
            if (FFI::isNull($result)) {
                return false;
            }

            self::call($ffi, 'daveWelcomeResultDestroy', $result);

            return true;
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return false;
        }
    }

    public static function getMarshalledKeyPackage(SessionHandle $session): ?string
    {
        $ffi = self::ffi();
        if (! $ffi instanceof FFI) {
            return null;
        }

        [$buffer, $length] = self::makeOutputByteBuffer();

        try {
            self::call($ffi, 'daveSessionGetMarshalledKeyPackage', $session->raw(), FFI::addr($buffer[0]), FFI::addr($length[0]));

            return self::takeOutputBytes($buffer, $length);
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return null;
        }
    }

    public static function getKeyRatchet(SessionHandle $session, string $userId): ?KeyRatchetHandle
    {
        $ffi = self::ffi();
        if (! $ffi instanceof FFI) {
            return null;
        }

        try {
            $keyRatchet = self::call($ffi, 'daveSessionGetKeyRatchet', $session->raw(), $userId);

            return FFI::isNull($keyRatchet) ? null : new KeyRatchetHandle($keyRatchet);
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return null;
        }
    }

    public static function createEncryptor(): ?EncryptorHandle
    {
        $ffi = self::ffi();
        if (! $ffi instanceof FFI) {
            return null;
        }

        try {
            $encryptor = self::call($ffi, 'daveEncryptorCreate');

            return FFI::isNull($encryptor) ? null : new EncryptorHandle($encryptor);
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return null;
        }
    }

    public static function createDecryptor(): ?DecryptorHandle
    {
        $ffi = self::ffi();
        if (! $ffi instanceof FFI) {
            return null;
        }

        try {
            $decryptor = self::call($ffi, 'daveDecryptorCreate');

            return FFI::isNull($decryptor) ? null : new DecryptorHandle($decryptor);
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return null;
        }
    }

    public static function configureEncryptorPassthrough(EncryptorHandle $encryptor, bool $passthroughMode): bool
    {
        $ffi = self::ffi();
        if (! $ffi instanceof FFI) {
            return false;
        }

        try {
            self::call($ffi, 'daveEncryptorSetPassthroughMode', $encryptor->raw(), $passthroughMode);

            return true;
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return false;
        }
    }

    public static function configureEncryptorKeyRatchet(EncryptorHandle $encryptor, KeyRatchetHandle $keyRatchet): bool
    {
        $ffi = self::ffi();
        if (! $ffi instanceof FFI) {
            return false;
        }

        try {
            self::call($ffi, 'daveEncryptorSetKeyRatchet', $encryptor->raw(), $keyRatchet->raw());

            return true;
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return false;
        }
    }

    public static function encryptWithEncryptor(EncryptorHandle $encryptor, string $frame, int $ssrc, int $codec = self::CODEC_OPUS): ?string
    {
        $ffi = self::ffi();
        if (! $ffi instanceof FFI) {
            return null;
        }

        [$framePointer, $frameLength, $frameBuffer] = self::makeBytePointer($frame);
        if ($framePointer === null) {
            return null;
        }

        try {
            self::call($ffi, 'daveEncryptorAssignSsrcToCodec', $encryptor->raw(), $ssrc, $codec);

            $maxCiphertextSize = (int) self::call(
                $ffi,
                'daveEncryptorGetMaxCiphertextByteSize',
                $encryptor->raw(),
                self::MEDIA_TYPE_AUDIO,
                $frameLength
            );
            if ($maxCiphertextSize <= 0) {
                return null;
            }

            $encryptedFrame = $ffi->new("uint8_t[{$maxCiphertextSize}]");
            $bytesWritten = $ffi->new('size_t[1]');

            $result = (int) self::call(
                $ffi,
                'daveEncryptorEncrypt',
                $encryptor->raw(),
                self::MEDIA_TYPE_AUDIO,
                $ssrc,
                $framePointer,
                $frameLength,
                FFI::addr($encryptedFrame[0]),
                $maxCiphertextSize,
                FFI::addr($bytesWritten[0])
            );

            if ($result !== self::RESULT_SUCCESS) {
                return null;
            }

            return FFI::string(FFI::addr($encryptedFrame[0]), (int) $bytesWritten[0]);
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return null;
        }
    }

    public static function configureDecryptorPassthrough(DecryptorHandle $decryptor, bool $passthroughMode): bool
    {
        $ffi = self::ffi();
        if (! $ffi instanceof FFI) {
            return false;
        }

        try {
            self::call($ffi, 'daveDecryptorTransitionToPassthroughMode', $decryptor->raw(), $passthroughMode);

            return true;
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return false;
        }
    }

    public static function configureDecryptorKeyRatchet(DecryptorHandle $decryptor, KeyRatchetHandle $keyRatchet): bool
    {
        $ffi = self::ffi();
        if (! $ffi instanceof FFI) {
            return false;
        }

        try {
            self::call($ffi, 'daveDecryptorTransitionToKeyRatchet', $decryptor->raw(), $keyRatchet->raw());

            return true;
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return false;
        }
    }

    public static function decryptWithDecryptor(DecryptorHandle $decryptor, string $frame): string|false|null
    {
        $ffi = self::ffi();
        if (! $ffi instanceof FFI) {
            return null;
        }

        [$framePointer, $frameLength, $frameBuffer] = self::makeBytePointer($frame);
        if ($framePointer === null) {
            return false;
        }

        try {
            $maxPlaintextSize = (int) self::call(
                $ffi,
                'daveDecryptorGetMaxPlaintextByteSize',
                $decryptor->raw(),
                self::MEDIA_TYPE_AUDIO,
                $frameLength
            );
            if ($maxPlaintextSize <= 0) {
                return false;
            }

            $plaintextFrame = $ffi->new("uint8_t[{$maxPlaintextSize}]");
            $bytesWritten = $ffi->new('size_t[1]');

            $result = (int) self::call(
                $ffi,
                'daveDecryptorDecrypt',
                $decryptor->raw(),
                self::MEDIA_TYPE_AUDIO,
                $framePointer,
                $frameLength,
                FFI::addr($plaintextFrame[0]),
                $maxPlaintextSize,
                FFI::addr($bytesWritten[0])
            );

            if ($result !== self::RESULT_SUCCESS) {
                return false;
            }

            return FFI::string(FFI::addr($plaintextFrame[0]), (int) $bytesWritten[0]);
        } catch (\Throwable $e) {
            self::$lastLoadError = $e->getMessage();

            return null;
        }
    }

    public static function destroyNativeHandle(mixed $handle, string $destroyMethod): void
    {
        $ffi = self::ffi();
        if (! $ffi instanceof FFI || $handle === null) {
            return;
        }

        try {
            self::call($ffi, $destroyMethod, $handle);
        } catch (\Throwable $e) {
            self::$lastDestroyError = "{$destroyMethod}: {$e->getMessage()}";
        }
    }

    protected static function load(): void
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
            $libraryPath = self::resolveDefaultLibraryPath();
        }

        $definitions = self::resolveDefinitions($libraryPath);

        try {
            self::$ffi = FFI::cdef(
                $definitions,
                $libraryPath
            );
        } catch (\Throwable $e) {
            self::$ffi = null;
            self::$lastLoadError = $e->getMessage();
        }
    }

    /**
     * Resolves the default library path by probing known locations.
     *
     * Probe order:
     * 1. Package root (relative to __DIR__): .cache/libdave/lib/libdave.{ext}
     * 2. Working directory: .cache/libdave/lib/libdave.{ext}
     * 3. Bare filename: libdave.{ext} (system library path)
     */
    private static function resolveDefaultLibraryPath(): string
    {
        $relative = match (PHP_OS_FAMILY) {
            'Darwin' => '.cache/libdave/lib/libdave.dylib',
            'Windows' => '.cache/libdave/bin/libdave.dll',
            default => '.cache/libdave/lib/libdave.so',
        };
        $libFile = basename($relative);

        // Package root: __DIR__ is src/Discord/Voice/Dave — walk up 4 dirs
        $packageRoot = dirname(__DIR__, 4);
        $packagePath = $packageRoot.DIRECTORY_SEPARATOR.$relative;
        if (is_file($packagePath)) {
            return $packagePath;
        }

        // Working directory
        $cwdPath = getcwd().DIRECTORY_SEPARATOR.$relative;
        if (is_file($cwdPath)) {
            return $cwdPath;
        }

        return $libFile;
    }

    /**
     * Resolves FFI C definitions from the dave.h header file co-located with
     * the resolved library. Throws if the header cannot be found or parsed.
     */
    private static function resolveDefinitions(string $libraryPath): string
    {
        $headerPath = self::deriveHeaderPath($libraryPath);

        if ($headerPath === null) {
            throw new \RuntimeException(
                'dave.h header not found alongside library at '.$libraryPath.'. '
                .'Run scripts/setup-libdave.sh to install both the library and headers.'
            );
        }

        $parsed = self::loadHeaderDefinitions($headerPath);
        if ($parsed === null) {
            throw new \RuntimeException(
                'Failed to parse dave.h header at '.$headerPath.'. '
                .'The file may be empty or unreadable.'
            );
        }

        return self::FFI_TYPEDEF_PRELUDE."\n".$parsed;
    }

    /**
     * Derives the dave.h header path from a resolved library path.
     *
     * Expected layout:
     *   Linux/macOS: {root}/.cache/libdave/lib/libdave.{so|dylib}
     *   Windows:     {root}/.cache/libdave/bin/libdave.dll
     * Header:        {root}/.cache/libdave/include/dave/dave.h
     */
    private static function deriveHeaderPath(string $libraryPath): ?string
    {
        $libDir = dirname($libraryPath);
        $dirName = basename($libDir);
        if ($dirName !== 'lib' && $dirName !== 'bin') {
            return null;
        }

        $headerPath = dirname($libDir).'/include/dave/dave.h';

        return is_file($headerPath) ? $headerPath : null;
    }

    /**
     * Reads and preprocesses the dave.h C header into FFI-compatible declarations.
     *
     * Handles: preprocessor directives, DAVE_EXPORT attribute, DECLARE_OPAQUE_HANDLE
     * macro expansion, extern "C" blocks, and C doc comments.
     */
    public static function loadHeaderDefinitions(string $headerPath): ?string
    {
        $content = @file_get_contents($headerPath);
        if ($content === false) {
            return null;
        }

        // Strip block comments (/** ... */ and /* ... */)
        $content = (string) preg_replace('/\/\*[\s\S]*?\*\//', '', $content);

        // Strip single-line comments (// ...)
        $content = (string) preg_replace('/\/\/[^\n]*/', '', $content);

        $lines = explode("\n", $content);
        $result = [];
        $ifdefDepth = 0;
        $insideIfdef = false;

        foreach ($lines as $line) {
            $trimmed = ltrim($line);

            // Skip preprocessor directives, tracking #if depth
            if (str_starts_with($trimmed, '#')) {
                if (preg_match('/^#\s*(?:if|ifdef|ifndef)\b/', $trimmed)) {
                    $ifdefDepth++;
                    $insideIfdef = true;
                } elseif (preg_match('/^#\s*endif\b/', $trimmed)) {
                    $ifdefDepth--;
                    if ($ifdefDepth <= 0) {
                        $ifdefDepth = 0;
                        $insideIfdef = false;
                    }
                }

                continue;
            }

            // Skip extern "C" { and its closing }
            if (preg_match('/^\s*extern\s+"C"\s*\{/', $trimmed)) {
                continue;
            }

            // Expand DECLARE_OPAQUE_HANDLE(Name) → typedef struct Name_s* Name
            $expanded = preg_replace(
                '/DECLARE_OPAQUE_HANDLE\(\s*(\w+)\s*\)\s*;/',
                'typedef struct ${1}_s* ${1};',
                $trimmed
            );
            if ($expanded !== $trimmed) {
                $result[] = $expanded;

                continue;
            }

            // Strip DAVE_EXPORT attribute from function declarations
            $stripped = str_replace('DAVE_EXPORT ', '', $trimmed);

            // Skip empty lines and lone closing braces (from extern "C")
            if ($stripped === '' || $stripped === '}') {
                continue;
            }

            $result[] = $stripped;
        }

        $output = implode("\n", $result);

        return trim($output) !== '' ? $output : null;
    }

    private static function ffi(): ?FFI
    {
        self::load();

        return self::$ffi;
    }

    /**
     * @return array{0:mixed,1:int,2:mixed}
     */
    protected static function makeBytePointer(string $bytes): array
    {
        $ffi = self::$ffi;
        $length = strlen($bytes);
        if (! $ffi instanceof FFI || $length === 0) {
            return [null, $length, null];
        }

        $buffer = $ffi->new("uint8_t[{$length}]", false);
        FFI::memcpy($buffer, $bytes, $length);

        return [FFI::addr($buffer[0]), $length, $buffer];
    }

    /**
     * @param list<string> $strings
     *
     * @return array{0:mixed,1:list<mixed>}
     */
    protected static function makeStringPointerArray(array $strings): array
    {
        $ffi = self::$ffi;
        if (! $ffi instanceof FFI || $strings === []) {
            return [null, []];
        }

        $pointers = $ffi->new('char*['.count($strings).']', false);
        $buffers = [];

        foreach (array_values($strings) as $index => $string) {
            $len = strlen($string);
            $buffer = $ffi->new('char['.($len + 1).']', false);
            FFI::memcpy($buffer, $string . "\0", $len + 1);
            $buffers[] = $buffer;
            $pointers[$index] = FFI::addr($buffer[0]);
        }

        return [$pointers, $buffers];
    }

    /**
     * @return array{0:mixed,1:mixed}
     */
    protected static function makeOutputByteBuffer(): array
    {
        $ffi = self::$ffi;

        return [
            $ffi->new('uint8_t*[1]'),
            $ffi->new('size_t[1]'),
        ];
    }

    protected static function takeOutputBytes(mixed $buffer, mixed $length): ?string
    {
        $ffi = self::$ffi;
        if (! $ffi instanceof FFI || FFI::isNull($buffer[0])) {
            return null;
        }

        try {
            return FFI::string(FFI::cast('char*', $buffer[0]), (int) $length[0]);
        } finally {
            self::call($ffi, 'daveFree', $buffer[0]);
        }
    }

    protected static function call(FFI $ffi, string $method, mixed ...$arguments): mixed
    {
        /** @var mixed $native */
        $native = $ffi;

        return $native->$method(...$arguments);
    }
}
