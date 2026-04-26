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

namespace Discord\Tests\Unit\Dave;

use Discord\Voice\Dave\Runtime;
use Discord\Voice\Dave\SessionHandle;
use Discord\Voice\Exceptions\Libraries\LibDaveNotFoundException;
use PHPUnit\Framework\TestCase;

$originalDaveLibraryPath = null;

beforeEach(function () use (&$originalDaveLibraryPath): void {
    $originalDaveLibraryPath = getenv('DISCORDPHP_DAVE_LIBRARY') ?: null;
    Runtime::reset();
});

afterEach(function () use (&$originalDaveLibraryPath): void {
    Runtime::reset();

    if ($originalDaveLibraryPath === null) {
        putenv('DISCORDPHP_DAVE_LIBRARY');

        return;
    }

    putenv('DISCORDPHP_DAVE_LIBRARY='.$originalDaveLibraryPath);
});

it('uses encrypt, decrypt, and commit welcome callbacks', function (): void {
    Runtime::configureCallbacks(
        fn (string $frame, int $protocolVersion): ?string => "enc:{$protocolVersion}:{$frame}",
        fn (string $frame, int $protocolVersion): string => "dec:{$protocolVersion}:{$frame}",
        fn (string $payload, int $protocolVersion): ?string => "commit:{$protocolVersion}:{$payload}"
    );

    $this->assertSame('enc:1:frame', Runtime::encryptMediaFrame('frame', 1));
    $this->assertSame('dec:1:frame', Runtime::decryptMediaFrame('frame', 1));
    $this->assertSame('commit:1:proposals', Runtime::buildMlsCommitWelcome('proposals', 1));
});

it('falls back to passthrough or null for protocol zero', function (): void {
    $this->assertSame('frame', Runtime::encryptMediaFrame('frame', 0));
    $this->assertSame('frame', Runtime::decryptMediaFrame('frame', 0));
    $this->assertNull(Runtime::buildMlsCommitWelcome('proposals', 0));
});

it('loads the native runtime and reports the max protocol version', function () use (&$originalDaveLibraryPath): void {
    requireNativeDaveRuntime($originalDaveLibraryPath);

    $this->assertTrue(Runtime::isAvailable());
    $this->assertSame(1, Runtime::maxProtocolVersion());
});

it('supports the native session lifecycle and key package generation', function () use (&$originalDaveLibraryPath): void {
    requireNativeDaveRuntime($originalDaveLibraryPath);

    $session = Runtime::createSession();
    $this->assertNotNull($session);

    try {
        $this->assertTrue(Runtime::initializeSession($session, 1, 123, '111'));
        $this->assertSame(1, Runtime::getSessionProtocolVersion($session));

        $keyPackage = Runtime::getMarshalledKeyPackage($session);
        $this->assertIsString($keyPackage);
        $this->assertNotSame('', $keyPackage);

        $this->assertTrue(Runtime::resetSession($session));
    } finally {
        $session->destroy();
    }
});

it('round trips passthrough frames with the native encryptor and decryptor', function () use (&$originalDaveLibraryPath): void {
    requireNativeDaveRuntime($originalDaveLibraryPath);

    $encryptor = Runtime::createEncryptor();
    $decryptor = Runtime::createDecryptor();

    $this->assertNotNull($encryptor);
    $this->assertNotNull($decryptor);

    try {
        $this->assertTrue(Runtime::configureEncryptorPassthrough($encryptor, true));
        $this->assertTrue(Runtime::configureDecryptorPassthrough($decryptor, true));

        $frame = hex2bin('0dc5aedd5bdc3f20be5697e54dd1f437');
        $this->assertIsString($frame);

        $encrypted = Runtime::encryptWithEncryptor($encryptor, $frame, 0);
        $this->assertSame($frame, $encrypted);

        $decrypted = Runtime::decryptWithDecryptor($decryptor, $encrypted);
        $this->assertSame($frame, $decrypted);
    } finally {
        $encryptor->destroy();
        $decryptor->destroy();
    }
});

// VULN-10 regression: makeBytePointer null guard on empty payload

it('buildMlsCommitWelcomeWithSession returns null for empty proposals payload without crash', function (): void {
    Runtime::configureCallbacks(availabilityOverride: false);

    $session = makeStubSessionHandle();

    $this->assertNull(Runtime::buildMlsCommitWelcomeWithSession($session, '', []));
});

it('processCommit returns null for empty commit payload without crash', function (): void {
    Runtime::configureCallbacks(availabilityOverride: false);

    $session = makeStubSessionHandle();

    $this->assertNull(Runtime::processCommit($session, ''));
});

it('processWelcome returns false for empty welcome payload without crash', function (): void {
    Runtime::configureCallbacks(availabilityOverride: false);

    $session = makeStubSessionHandle();

    $this->assertFalse(Runtime::processWelcome($session, '', []));
});

it('setExternalSender returns false for empty sender payload without crash', function (): void {
    Runtime::configureCallbacks(availabilityOverride: false);

    $session = makeStubSessionHandle();

    $this->assertFalse(Runtime::setExternalSender($session, ''));
});

it('resolveDefaultLibraryPath throws LibDaveNotFoundException when library files are absent', function (): void {
    $libFile = match (PHP_OS_FAMILY) {
        'Darwin' => '.cache/libdave/lib/libdave.dylib',
        'Windows' => '.cache/libdave/bin/libdave.dll',
        default => '.cache/libdave/lib/libdave.so',
    };
    $packageRoot = dirname((new \ReflectionClass(Runtime::class))->getFileName(), 5);
    if (is_file($packageRoot.DIRECTORY_SEPARATOR.$libFile) || is_file(getcwd().DIRECTORY_SEPARATOR.$libFile)) {
        $this->markTestSkipped('libdave is available in cache; test requires missing library.');
    }

    putenv('DISCORDPHP_DAVE_LIBRARY');
    Runtime::reset();

    $method = new \ReflectionMethod(Runtime::class, 'resolveDefaultLibraryPath');
    $method->setAccessible(true);

    $this->expectException(LibDaveNotFoundException::class);
    $method->invoke(null);
});

it('isAvailable returns false and does not throw when library files are absent', function (): void {
    $libFile = match (PHP_OS_FAMILY) {
        'Darwin' => '.cache/libdave/lib/libdave.dylib',
        'Windows' => '.cache/libdave/bin/libdave.dll',
        default => '.cache/libdave/lib/libdave.so',
    };
    $packageRoot = dirname((new \ReflectionClass(Runtime::class))->getFileName(), 5);
    if (is_file($packageRoot.DIRECTORY_SEPARATOR.$libFile) || is_file(getcwd().DIRECTORY_SEPARATOR.$libFile)) {
        $this->markTestSkipped('libdave is available in cache; test requires missing library.');
    }

    putenv('DISCORDPHP_DAVE_LIBRARY');
    Runtime::reset();

    $this->assertFalse(Runtime::isAvailable());
});

function makeStubSessionHandle(): SessionHandle
{
    $session = (new \ReflectionClass(SessionHandle::class))->newInstanceWithoutConstructor();

    $handleProp = new \ReflectionProperty(\Discord\Voice\Dave\NativeHandle::class, 'handle');
    $handleProp->setAccessible(true);
    $handleProp->setValue($session, null);

    return $session;
}

function requireNativeDaveRuntime(?string $libraryPath): void
{
    if ($libraryPath === null || $libraryPath === '' || ! is_file($libraryPath)) {
        TestCase::markTestSkipped('Native libdave runtime not configured for this test run.');
    }

    putenv('DISCORDPHP_DAVE_LIBRARY='.$libraryPath);
    Runtime::reset();
}
