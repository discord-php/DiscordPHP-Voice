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

namespace Discord\Tests\Feature\Voice;

use Discord\Discord;
use Discord\Voice\Client\WS;
use Discord\Voice\Dave\BinaryFrame;
use Discord\Voice\Dave\EncryptorHandle;
use Discord\Voice\Dave\Runtime;
use Discord\Voice\Dave\SessionHandle;
use Discord\Voice\Dave\State;
use Discord\WebSockets\Op;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ratchet\Client\WebSocket;

afterEach(function (): void {
    Runtime::reset();
});

// ──────────────────────────────────────────────────────────────
// initializeDaveRuntimeState
// ──────────────────────────────────────────────────────────────

it('initializeDaveRuntimeState resets protocol state and returns true when protocolVersion is 0', function (): void {
    $sentPayloads = [];
    $ws = makeWsForSessionInitTest($this, $sentPayloads);

    $state = getSessionInitDaveState($ws);
    $state->setProtocolVersion(1); // bump to non-zero to verify it gets reset

    $result = invokeSessionInitMethod($ws, 'initializeDaveRuntimeState', [0]);

    expect($result)->toBeTrue()
        ->and($state->protocolVersion)->toBe(0)
        ->and($state->passthroughMode)->toBeTrue()
        ->and($sentPayloads)->toBeEmpty();
});

it('initializeDaveRuntimeState returns false when identity selfUserId is not set', function (): void {
    $sentPayloads = [];
    $ws = makeWsForSessionInitTest($this, $sentPayloads);

    $state = getSessionInitDaveState($ws);
    $state->selfUserId = null; // clear identity

    $result = invokeSessionInitMethod($ws, 'initializeDaveRuntimeState', [1]);

    expect($result)->toBeFalse()
        ->and($sentPayloads)->toBeEmpty();
});

it('initializeDaveRuntimeState returns false when identity groupId is not set', function (): void {
    $sentPayloads = [];
    $ws = makeWsForSessionInitTest($this, $sentPayloads);

    $state = getSessionInitDaveState($ws);
    $state->groupId = null; // clear group

    $result = invokeSessionInitMethod($ws, 'initializeDaveRuntimeState', [1]);

    expect($result)->toBeFalse()
        ->and($sentPayloads)->toBeEmpty();
});

it('initializeDaveRuntimeState resets existing session when resetState is true', function (): void {
    $sentPayloads = [];
    $ws = makeWsForSessionInitTest($this, $sentPayloads);

    $state = getSessionInitDaveState($ws);
    $existingSession = new SessionHandle(new \stdClass());
    $state->replaceSession($existingSession);

    expect($state->session)->not->toBeNull();

    // With resetState=true, resetProtocolState() nulls the session before createSession is called.
    // Without libdave, createSession returns null so session remains null.
    // With libdave, createSession creates a new session — either way the old session is gone.
    invokeSessionInitMethod($ws, 'initializeDaveRuntimeState', [1, true]);

    expect($state->session)->not->toBe($existingSession);
});

it('initializeDaveRuntimeState returns false when createSession fails', function (): void {
    $sentPayloads = [];
    $ws = makeWsForSessionInitTest($this, $sentPayloads);

    // Inject a null-returning createSession callback to simulate session creation failure
    // regardless of libdave availability.
    Runtime::configureCallbacks(createSessionCallback: fn () => null);

    // Identity is set (default in makeWsForSessionInitTest), session is null → createSession returns null → false.
    $result = invokeSessionInitMethod($ws, 'initializeDaveRuntimeState', [1]);

    expect($result)->toBeFalse()
        ->and($sentPayloads)->toBeEmpty();
});

// ──────────────────────────────────────────────────────────────
// sendDaveKeyPackage
// ──────────────────────────────────────────────────────────────

it('sendDaveKeyPackage sends nothing when session is null', function (): void {
    $sentPayloads = [];
    $ws = makeWsForSessionInitTest($this, $sentPayloads);

    // session is null by default
    invokeSessionInitMethod($ws, 'sendDaveKeyPackage');

    expect($sentPayloads)->toBeEmpty();
});

it('sendDaveKeyPackage sends nothing when getMarshalledKeyPackage fails without libdave', function (): void {
    $sentPayloads = [];
    $ws = makeWsForSessionInitTest($this, $sentPayloads);

    $state = getSessionInitDaveState($ws);
    $fakeSession = new SessionHandle(new \stdClass());
    $state->replaceSession($fakeSession);

    // getMarshalledKeyPackage requires the native FFI; without libdave it returns null → no send.
    invokeSessionInitMethod($ws, 'sendDaveKeyPackage');

    expect($sentPayloads)->toBeEmpty();
});

// ──────────────────────────────────────────────────────────────
// handleDavePrepareEpoch
// ──────────────────────────────────────────────────────────────

it('handleDavePrepareEpoch records epoch and returns early when dave_protocol_version is 0', function (): void {
    $sentPayloads = [];
    $ws = makeWsForSessionInitTest($this, $sentPayloads);

    $data = new \stdClass();
    $data->d = ['epoch' => 3, 'dave_protocol_version' => 0];

    invokeSessionInitMethod($ws, 'handleDavePrepareEpoch', [$data]);

    $state = getSessionInitDaveState($ws);
    expect($state->epoch)->toBe(3)
        ->and($sentPayloads)->toBeEmpty();
});

it('handleDavePrepareEpoch returns early and sends no key package when protocol version is 0', function (): void {
    $sentPayloads = [];
    $ws = makeWsForSessionInitTest($this, $sentPayloads);

    // dave_protocol_version=0 → resolveDaveProtocolVersion returns 0 → early return without session or key package.
    $data = new \stdClass();
    $data->d = ['epoch' => 1, 'dave_protocol_version' => 0];

    invokeSessionInitMethod($ws, 'handleDavePrepareEpoch', [$data]);

    $state = getSessionInitDaveState($ws);
    expect($state->epoch)->toBe(1)
        ->and($sentPayloads)->toBeEmpty();
});

it('handleDavePrepareEpoch prepares transition when transition_id is present', function (): void {
    $sentPayloads = [];
    $ws = makeWsForSessionInitTest($this, $sentPayloads);

    $data = new \stdClass();
    $data->d = ['epoch' => 2, 'dave_protocol_version' => 0, 'transition_id' => 7];

    invokeSessionInitMethod($ws, 'handleDavePrepareEpoch', [$data]);

    $state = getSessionInitDaveState($ws);
    expect($state->epoch)->toBe(2)
        ->and($state->pendingTransitionId)->toBe(7)
        ->and($sentPayloads)->toBeEmpty();
});

it('handleDavePrepareEpoch sets latestPreparedTransitionVersion when no transition_id', function (): void {
    $sentPayloads = [];
    $ws = makeWsForSessionInitTest($this, $sentPayloads);

    $data = new \stdClass();
    $data->d = ['epoch' => 1, 'dave_protocol_version' => 0];

    invokeSessionInitMethod($ws, 'handleDavePrepareEpoch', [$data]);

    $state = getSessionInitDaveState($ws);
    // protocolVersion resolved to 0; latestPreparedTransitionVersion is set to 0.
    expect($state->latestPreparedTransitionVersion)->toBe(0)
        ->and($state->pendingTransitionId)->toBeNull();
});

it('handleDavePrepareEpoch closes the socket and sends no packets when initializeDaveRuntimeState fails', function (): void {
    $sentPayloads = [];
    $ws = makeWsForSessionInitTest($this, $sentPayloads);

    // Force createSession to fail so initializeDaveRuntimeState returns false.
    Runtime::configureCallbacks(createSessionCallback: fn () => null);

    $data = new \stdClass();
    $data->d = ['epoch' => 1, 'dave_protocol_version' => 1];

    invokeSessionInitMethod($ws, 'handleDavePrepareEpoch', [$data]);

    // Fail-closed: socket is closed and no gateway packets were sent.
    expect($sentPayloads)->toBeEmpty();
});

// ──────────────────────────────────────────────────────────────
// handleDaveMlsExternalSender
// ──────────────────────────────────────────────────────────────

it('handleDaveMlsExternalSender ignores non-BinaryFrame data without crashing', function (): void {
    $sentPayloads = [];
    $ws = makeWsForSessionInitTest($this, $sentPayloads);

    invokeSessionInitMethod($ws, 'handleDaveMlsExternalSender', [new \stdClass()]);

    $state = getSessionInitDaveState($ws);
    expect($state->externalSenderPackage)->toBeNull()
        ->and($sentPayloads)->toBeEmpty();
});

it('handleDaveMlsExternalSender stores external sender bytes from BinaryFrame payload', function (): void {
    $sentPayloads = [];
    $ws = makeWsForSessionInitTest($this, $sentPayloads);

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_EXTERNAL_SENDER, 'sender-key-bytes');
    invokeSessionInitMethod($ws, 'handleDaveMlsExternalSender', [$frame]);

    $state = getSessionInitDaveState($ws);
    expect($state->externalSenderPackage)->toBe('sender-key-bytes');
});

it('handleDaveMlsExternalSender sends pending key package when session is ready', function (): void {
    $sentPayloads = [];
    $ws = makeWsForSessionInitTest($this, $sentPayloads);

    $state = getSessionInitDaveState($ws);
    $state->replaceSession(new SessionHandle(new \stdClass()));
    $state->replaceEncryptor(new EncryptorHandle(new \stdClass()));

    Runtime::configureCallbacks(
        keyPackageCallback: fn (SessionHandle $session): ?string => 'key-package'
    );

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_EXTERNAL_SENDER, 'sender-key-bytes');
    invokeSessionInitMethod($ws, 'handleDaveMlsExternalSender', [$frame]);

    expect($sentPayloads)->not->toBeEmpty();
    $sentFrame = BinaryFrame::fromClientPayload($sentPayloads[0]);

    expect($sentFrame)->not->toBeNull()
        ->and($sentFrame?->opcode)->toBe(Op::VOICE_DAVE_MLS_KEY_PACKAGE)
        ->and($sentFrame?->payload)->toBe('key-package')
        ->and($state->keyPackageSent)->toBeTrue();
});

it('handleDaveMlsExternalSender stores empty string payload from BinaryFrame', function (): void {
    $sentPayloads = [];
    $ws = makeWsForSessionInitTest($this, $sentPayloads);

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_EXTERNAL_SENDER, '');
    invokeSessionInitMethod($ws, 'handleDaveMlsExternalSender', [$frame]);

    $state = getSessionInitDaveState($ws);
    expect($state->externalSenderPackage)->toBe('');
});

it('handleDaveMlsExternalSender overwrites previously stored sender package', function (): void {
    $sentPayloads = [];
    $ws = makeWsForSessionInitTest($this, $sentPayloads);

    $state = getSessionInitDaveState($ws);
    $state->externalSenderPackage = 'old-sender';

    $frame = new BinaryFrame(2, Op::VOICE_DAVE_MLS_EXTERNAL_SENDER, 'new-sender');
    invokeSessionInitMethod($ws, 'handleDaveMlsExternalSender', [$frame]);

    expect($state->externalSenderPackage)->toBe('new-sender');
});

// ──────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────

/**
 * @param array<int, string> $sentPayloads Reference to collect raw send() strings.
 */
function makeWsForSessionInitTest(TestCase $test, array &$sentPayloads): WS
{
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();

    $state = new State();
    $state->setIdentity('42', 123); // valid identity for most tests

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, new NullLogger());

    $daveStateProperty = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProperty->setAccessible(true);
    $daveStateProperty->setValue($ws, $state);

    $discordProperty = new \ReflectionProperty(WS::class, 'discord');
    $discordProperty->setAccessible(true);
    $discordProperty->setValue($ws, $discord);

    $socket = invokeSessionInitMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send', 'close'])
        ->getMock();
    $socket->method('send')->willReturnCallback(function (mixed $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload instanceof \Ratchet\RFC6455\Messaging\Frame
            ? $payload->getPayload()
            : $payload;
    });

    $socketProperty = new \ReflectionProperty(WS::class, 'socket');
    $socketProperty->setAccessible(true);
    $socketProperty->setValue($ws, $socket);

    return $ws;
}

function getSessionInitDaveState(WS $ws): State
{
    $prop = new \ReflectionProperty(WS::class, 'daveState');
    $prop->setAccessible(true);

    return $prop->getValue($ws);
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeSessionInitMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}
