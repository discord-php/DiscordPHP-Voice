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

$nativeFfiOriginalDavePath = null;

beforeEach(function () use (&$nativeFfiOriginalDavePath): void {
    $nativeFfiOriginalDavePath = getenv('DISCORDPHP_DAVE_LIBRARY') ?: null;
    Runtime::reset();
});

afterEach(function () use (&$nativeFfiOriginalDavePath): void {
    Runtime::reset();

    if ($nativeFfiOriginalDavePath === null) {
        putenv('DISCORDPHP_DAVE_LIBRARY');

        return;
    }

    putenv('DISCORDPHP_DAVE_LIBRARY='.$nativeFfiOriginalDavePath);
});

it('setSessionProtocolVersion updates version on an initialized real session', function () use (&$nativeFfiOriginalDavePath): void {
    requireNativeDaveRuntimeFfi($nativeFfiOriginalDavePath);

    $session = Runtime::createSession();
    $this->assertNotNull($session);

    try {
        $this->assertTrue(Runtime::initializeSession($session, 1, 123, '111'));
        $this->assertTrue(Runtime::setSessionProtocolVersion($session, 1));
        $this->assertSame(1, Runtime::getSessionProtocolVersion($session));
    } finally {
        $session->destroy();
    }
});

it('setExternalSender with non-empty bytes on real session does not crash', function () use (&$nativeFfiOriginalDavePath): void {
    requireNativeDaveRuntimeFfi($nativeFfiOriginalDavePath);

    $session = Runtime::createSession();
    $this->assertNotNull($session);

    try {
        Runtime::initializeSession($session, 1, 123, '111');
        $result = Runtime::setExternalSender($session, str_repeat("\x00", 32));
        $this->assertIsBool($result);
    } finally {
        $session->destroy();
    }
});

it('getKeyRatchet returns null for unknown user id via native FFI path', function () use (&$nativeFfiOriginalDavePath): void {
    requireNativeDaveRuntimeFfi($nativeFfiOriginalDavePath);

    $session = Runtime::createSession();
    $this->assertNotNull($session);

    try {
        Runtime::initializeSession($session, 1, 123, '111');
        $result = Runtime::getKeyRatchet($session, '999999999999999999');
        $this->assertNull($result);
    } finally {
        $session->destroy();
    }
});

it('configureDecryptorPassthrough false works on native decryptor', function () use (&$nativeFfiOriginalDavePath): void {
    requireNativeDaveRuntimeFfi($nativeFfiOriginalDavePath);

    $decryptor = Runtime::createDecryptor();
    $this->assertNotNull($decryptor);

    try {
        $this->assertTrue(Runtime::configureDecryptorPassthrough($decryptor, false));
    } finally {
        $decryptor->destroy();
    }
});

it('encryptWithEncryptor returns null when no key ratchet is configured', function () use (&$nativeFfiOriginalDavePath): void {
    requireNativeDaveRuntimeFfi($nativeFfiOriginalDavePath);

    $encryptor = Runtime::createEncryptor();
    $this->assertNotNull($encryptor);

    try {
        $result = Runtime::encryptWithEncryptor($encryptor, hex2bin('0dc5aedd5bdc3f20be5697e54dd1f437'), 0);
        $this->assertNull($result);
    } finally {
        $encryptor->destroy();
    }
});

it('decryptWithDecryptor returns false for garbage frame without key ratchet', function () use (&$nativeFfiOriginalDavePath): void {
    requireNativeDaveRuntimeFfi($nativeFfiOriginalDavePath);

    $decryptor = Runtime::createDecryptor();
    $this->assertNotNull($decryptor);

    try {
        $this->assertTrue(Runtime::configureDecryptorPassthrough($decryptor, false));
        $result = Runtime::decryptWithDecryptor($decryptor, hex2bin('0dc5aedd5bdc3f20be5697e54dd1f437'));
        $this->assertFalse($result);
    } finally {
        $decryptor->destroy();
    }
});

// Helpers

function requireNativeDaveRuntimeFfi(?string $libraryPath): void
{
    if ($libraryPath !== null && $libraryPath !== '' && is_file($libraryPath)) {
        putenv('DISCORDPHP_DAVE_LIBRARY='.$libraryPath);
    }

    Runtime::reset();
    if (! Runtime::isAvailable()) {
        TestCase::markTestSkipped('Native libdave runtime not available for this test run: '.(Runtime::getLastLoadError() ?? 'unknown error'));
    }
}
