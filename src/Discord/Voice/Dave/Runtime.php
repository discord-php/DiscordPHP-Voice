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
    protected const DEFAULT_LIBRARY_PATH = 'libdave.so';
    protected const DAVE_FFI_DEFINITIONS = <<<'CDEF'
typedef unsigned char uint8_t;
typedef unsigned short uint16_t;
typedef unsigned int uint32_t;
typedef unsigned long size_t;
typedef unsigned long long uint64_t;
typedef _Bool bool;

typedef struct DAVESessionHandle_s* DAVESessionHandle;
typedef struct DAVECommitResultHandle_s* DAVECommitResultHandle;
typedef struct DAVEWelcomeResultHandle_s* DAVEWelcomeResultHandle;
typedef struct DAVEKeyRatchetHandle_s* DAVEKeyRatchetHandle;
typedef struct DAVEEncryptorHandle_s* DAVEEncryptorHandle;
typedef struct DAVEDecryptorHandle_s* DAVEDecryptorHandle;

typedef void (*DAVEMLSFailureCallback)(const char* source, const char* reason, void* userData);

uint16_t daveMaxSupportedProtocolVersion(void);
void daveFree(void* ptr);

DAVESessionHandle daveSessionCreate(void* context,
                                    const char* authSessionId,
                                    DAVEMLSFailureCallback callback,
                                    void* userData);
void daveSessionDestroy(DAVESessionHandle session);
void daveSessionInit(DAVESessionHandle session,
                     uint16_t version,
                     uint64_t groupId,
                     const char* selfUserId);
void daveSessionReset(DAVESessionHandle session);
void daveSessionSetProtocolVersion(DAVESessionHandle session, uint16_t version);
uint16_t daveSessionGetProtocolVersion(DAVESessionHandle session);
void daveSessionSetExternalSender(DAVESessionHandle session,
                                  const uint8_t* externalSender,
                                  size_t length);
void daveSessionProcessProposals(DAVESessionHandle session,
                                 const uint8_t* proposals,
                                 size_t length,
                                 const char** recognizedUserIds,
                                 size_t recognizedUserIdsLength,
                                 uint8_t** commitWelcomeBytes,
                                 size_t* commitWelcomeBytesLength);
DAVECommitResultHandle daveSessionProcessCommit(DAVESessionHandle session,
                                                const uint8_t* commit,
                                                size_t length);
DAVEWelcomeResultHandle daveSessionProcessWelcome(DAVESessionHandle session,
                                                  const uint8_t* welcome,
                                                  size_t length,
                                                  const char** recognizedUserIds,
                                                  size_t recognizedUserIdsLength);
void daveSessionGetMarshalledKeyPackage(DAVESessionHandle session,
                                        uint8_t** keyPackage,
                                        size_t* length);
DAVEKeyRatchetHandle daveSessionGetKeyRatchet(DAVESessionHandle session, const char* userId);

bool daveCommitResultIsFailed(DAVECommitResultHandle commitResultHandle);
bool daveCommitResultIsIgnored(DAVECommitResultHandle commitResultHandle);
void daveCommitResultDestroy(DAVECommitResultHandle commitResultHandle);
void daveWelcomeResultDestroy(DAVEWelcomeResultHandle welcomeResultHandle);

void daveKeyRatchetDestroy(DAVEKeyRatchetHandle keyRatchet);

DAVEEncryptorHandle daveEncryptorCreate(void);
void daveEncryptorDestroy(DAVEEncryptorHandle encryptor);
void daveEncryptorSetKeyRatchet(DAVEEncryptorHandle encryptor,
                                DAVEKeyRatchetHandle keyRatchet);
void daveEncryptorSetPassthroughMode(DAVEEncryptorHandle encryptor, bool passthroughMode);
void daveEncryptorAssignSsrcToCodec(DAVEEncryptorHandle encryptor,
                                    uint32_t ssrc,
                                    int codecType);
uint16_t daveEncryptorGetProtocolVersion(DAVEEncryptorHandle encryptor);
size_t daveEncryptorGetMaxCiphertextByteSize(DAVEEncryptorHandle encryptor,
                                             int mediaType,
                                             size_t frameSize);
bool daveEncryptorHasKeyRatchet(DAVEEncryptorHandle encryptor);
bool daveEncryptorIsPassthroughMode(DAVEEncryptorHandle encryptor);
int daveEncryptorEncrypt(DAVEEncryptorHandle encryptor,
                         int mediaType,
                         uint32_t ssrc,
                         const uint8_t* frame,
                         size_t frameLength,
                         uint8_t* encryptedFrame,
                         size_t encryptedFrameCapacity,
                         size_t* bytesWritten);

DAVEDecryptorHandle daveDecryptorCreate(void);
void daveDecryptorDestroy(DAVEDecryptorHandle decryptor);
void daveDecryptorTransitionToKeyRatchet(DAVEDecryptorHandle decryptor,
                                         DAVEKeyRatchetHandle keyRatchet);
void daveDecryptorTransitionToPassthroughMode(DAVEDecryptorHandle decryptor, bool passthroughMode);
int daveDecryptorDecrypt(DAVEDecryptorHandle decryptor,
                         int mediaType,
                         const uint8_t* encryptedFrame,
                         size_t encryptedFrameLength,
                         uint8_t* frame,
                         size_t frameCapacity,
                         size_t* bytesWritten);
size_t daveDecryptorGetMaxPlaintextByteSize(DAVEDecryptorHandle decryptor,
                                            int mediaType,
                                            size_t encryptedFrameSize);
CDEF;

    public const MEDIA_TYPE_AUDIO = 0;
    public const CODEC_OPUS = 1;

    protected const RESULT_SUCCESS = 0;

    protected static bool $loaded = false;

    protected static ?FFI $ffi = null;

    protected static ?string $lastLoadError = null;

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

    public static function reset(): void
    {
        self::$loaded = false;
        self::$ffi = null;
        self::$lastLoadError = null;
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

        try {
            self::call(
                $ffi,
                'daveSessionProcessProposals',
                $session->raw(),
                $proposalPointer,
                $proposalLength,
                $recognizedPointers,
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

            $encryptedFrame = $ffi->new("uint8_t[{$maxCiphertextSize}]", false);
            $bytesWritten = $ffi->new('size_t[1]', false);

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

            $plaintextFrame = $ffi->new("uint8_t[{$maxPlaintextSize}]", false);
            $bytesWritten = $ffi->new('size_t[1]', false);

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
        } catch (\Throwable) {
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

    protected static function ffi(): ?FFI
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
            $buffer = $ffi->new('char['.(strlen($string) + 1).']', false);
            FFI::memcpy($buffer, $string, strlen($string));
            $buffer[strlen($string)] = 0;
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
            $ffi->new('uint8_t*[1]', false),
            $ffi->new('size_t[1]', false),
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
