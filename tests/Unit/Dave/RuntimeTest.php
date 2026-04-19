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

function requireNativeDaveRuntime(?string $libraryPath): void
{
    if ($libraryPath === null || $libraryPath === '' || ! is_file($libraryPath)) {
        TestCase::markTestSkipped('Native libdave runtime not configured for this test run.');
    }

    putenv('DISCORDPHP_DAVE_LIBRARY='.$libraryPath);
    Runtime::reset();
}
