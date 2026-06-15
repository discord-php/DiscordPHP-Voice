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

afterEach(function (): void {
    Runtime::reset();
});

it('forces isAvailable to false when availabilityOverride is false', function (): void {
    Runtime::configureCallbacks(availabilityOverride: false);

    expect(Runtime::isAvailable())->toBeFalse();
});

it('forces isAvailable to true when availabilityOverride is true without a real library', function (): void {
    Runtime::configureCallbacks(availabilityOverride: true);

    expect(Runtime::isAvailable())->toBeTrue();
});

it('invokes the encrypt callback when configured', function (): void {
    $calls = [];
    Runtime::configureCallbacks(
        frameEncryptor: function (string $frame, int $version) use (&$calls): ?string {
            $calls[] = ['encrypt', $frame, $version];

            return 'ENC';
        },
    );

    expect(Runtime::encryptMediaFrame('payload', 1))->toBe('ENC');
    expect($calls)->toBe([['encrypt', 'payload', 1]]);
});

it('invokes the decrypt callback when configured', function (): void {
    $calls = [];
    Runtime::configureCallbacks(
        frameDecryptor: function (string $frame, int $version) use (&$calls): string {
            $calls[] = ['decrypt', $frame, $version];

            return 'DEC';
        },
    );

    expect(Runtime::decryptMediaFrame('cipher', 2))->toBe('DEC');
    expect($calls)->toBe([['decrypt', 'cipher', 2]]);
});

it('invokes the mls commit welcome builder callback when configured', function (): void {
    Runtime::configureCallbacks(
        mlsCommitWelcomeBuilder: fn (string $payload, int $version): ?string => "built:{$version}:{$payload}",
    );

    expect(Runtime::buildMlsCommitWelcome('proposals', 3))->toBe('built:3:proposals');
});

it('invokes the createSession callback with the auth session id', function (): void {
    $stubSession = makeStubSessionHandleForRuntimeCallbacks();
    $received = null;

    Runtime::configureCallbacks(
        createSessionCallback: function (?string $authSessionId) use (&$received, $stubSession): ?SessionHandle {
            $received = $authSessionId;

            return $stubSession;
        },
    );

    expect(Runtime::createSession('auth-123'))->toBe($stubSession);
    expect($received)->toBe('auth-123');
});

it('invokes the processCommit callback when configured', function (): void {
    $stubSession = makeStubSessionHandleForRuntimeCallbacks();
    $captured = null;

    Runtime::configureCallbacks(
        processCommitCallback: function (?SessionHandle $session, string $commit) use (&$captured): ?array {
            $captured = ['commit' => $commit, 'session' => $session];

            return ['failed' => false, 'ignored' => true];
        },
    );

    $result = Runtime::processCommit($stubSession, 'commit-bytes');

    expect($result)->toBe(['failed' => false, 'ignored' => true]);
    expect($captured['commit'])->toBe('commit-bytes');
    expect($captured['session'])->toBe($stubSession);
});

it('invokes the processWelcome callback when configured', function (): void {
    $stubSession = makeStubSessionHandleForRuntimeCallbacks();
    $captured = null;

    Runtime::configureCallbacks(
        processWelcomeCallback: function (?SessionHandle $session, string $welcome, array $recognized) use (&$captured): bool {
            $captured = ['welcome' => $welcome, 'recognized' => $recognized, 'session' => $session];

            return true;
        },
    );

    expect(Runtime::processWelcome($stubSession, 'welcome-bytes', ['1', '2']))->toBeTrue();
    expect($captured['welcome'])->toBe('welcome-bytes');
    expect($captured['recognized'])->toBe(['1', '2']);
    expect($captured['session'])->toBe($stubSession);
});

it('clears callbacks and overrides after reset', function (): void {
    $invoked = 0;
    Runtime::configureCallbacks(
        frameEncryptor: function (string $frame, int $version) use (&$invoked): ?string {
            $invoked++;

            return 'ENC';
        },
        availabilityOverride: true,
    );

    expect(Runtime::isAvailable())->toBeTrue();
    expect(Runtime::encryptMediaFrame('frame', 1))->toBe('ENC');

    Runtime::reset();

    expect(Runtime::encryptMediaFrame('frame', 1))->toBeNull();
    expect($invoked)->toBe(1);
});

it('returns null from getLastLoadError after reset', function (): void {
    Runtime::reset();

    expect(Runtime::getLastLoadError())->toBeNull();
});

it('returns null from getLastDestroyError after reset', function (): void {
    Runtime::reset();

    expect(Runtime::getLastDestroyError())->toBeNull();
});

it('reports a non-negative integer from maxProtocolVersion', function (): void {
    Runtime::reset();

    expect(Runtime::maxProtocolVersion())->toBeInt()->toBeGreaterThanOrEqual(0);
});

it('returns frame unchanged for protocol version 0 regardless of callbacks', function (): void {
    Runtime::configureCallbacks(
        frameEncryptor: fn (string $frame, int $version): ?string => 'should-not-run',
        frameDecryptor: fn (string $frame, int $version): string => 'should-not-run',
    );

    expect(Runtime::encryptMediaFrame('frame', 0))->toBe('frame');
    expect(Runtime::decryptMediaFrame('frame', 0))->toBe('frame');
});

// Helpers

function makeStubSessionHandleForRuntimeCallbacks(): SessionHandle
{
    $session = (new \ReflectionClass(SessionHandle::class))->newInstanceWithoutConstructor();

    $handleProp = new \ReflectionProperty(\Discord\Voice\Dave\NativeHandle::class, 'handle');
    $handleProp->setAccessible(true);
    $handleProp->setValue($session, null);

    return $session;
}
