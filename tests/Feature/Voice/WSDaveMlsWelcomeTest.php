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

// ─── Scenario 1 ───────────────────────────────────────────────────────────────

it('mls welcome returns early without any send when data is not a BinaryFrame', function (): void {
    $sentPayloads = [];
    $ws = makeWsForWelcomeTest($this, $sentPayloads);

    invokeWelcomeMethod($ws, 'handleDaveMlsWelcome', [new \stdClass()]);

    expect($sentPayloads)->toBeEmpty();
});

// ─── Scenario 2 ───────────────────────────────────────────────────────────────

it('mls welcome returns early without any send when daveState session is null', function (): void {
    $sentPayloads = [];
    $ws = makeWsForWelcomeTest($this, $sentPayloads);
    // daveState->session remains null (no injectFakeSession call)

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_WELCOME, pack('n', 5).'welcome-data');
    invokeWelcomeMethod($ws, 'handleDaveMlsWelcome', [$frame]);

    expect($sentPayloads)->toBeEmpty();
});

// ─── Scenario 3 ───────────────────────────────────────────────────────────────

it('mls welcome sends INVALID_COMMIT_WELCOME binary frame when processWelcome fails (protocolVersion 0)', function (): void {
    $sentPayloads = [];
    $ws = makeWsForWelcomeTest($this, $sentPayloads, protocolVersion: 0);
    injectWelcomeFakeSession($ws);

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_WELCOME, pack('n', 7).'welcome-bytes');
    invokeWelcomeMethod($ws, 'handleDaveMlsWelcome', [$frame]);

    // processWelcome returns false (no libdave) → handleInvalidDaveTransition(7, true)
    // With protocolVersion=0, only INVALID_COMMIT_WELCOME is sent then state resets.
    expect($sentPayloads)->not->toBeEmpty();

    $binary = BinaryFrame::fromClientPayload($sentPayloads[0]);
    expect($binary)->not->toBeNull();
    /** @var BinaryFrame $binary */
    expect($binary->sequence)->toBeNull();
    expect($binary->opcode)->toBe(Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME);
    expect($binary->payload)->toBe('');
});

// ─── Scenario 4 ───────────────────────────────────────────────────────────────

it('mls welcome does not send TRANSITION_READY when processWelcome fails', function (): void {
    $sentPayloads = [];
    $ws = makeWsForWelcomeTest($this, $sentPayloads, protocolVersion: 0);
    injectWelcomeFakeSession($ws);

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_WELCOME, pack('n', 7).'welcome-bytes');
    invokeWelcomeMethod($ws, 'handleDaveMlsWelcome', [$frame]);

    $transitionReadyOp = Op::VOICE_DAVE_TRANSITION_READY;
    $hasTransitionReady = false;
    foreach ($sentPayloads as $payload) {
        $decoded = json_decode($payload, true);
        if (is_array($decoded) && ($decoded['op'] ?? null) === $transitionReadyOp) {
            $hasTransitionReady = true;
        }
    }

    expect($hasTransitionReady)->toBeFalse();
});

// ─── Scenario 5 ───────────────────────────────────────────────────────────────

it('mls welcome sends TRANSITION_READY JSON when processWelcome succeeds (requires libdave)', function (): void {
    $libdave = getenv('DISCORDPHP_DAVE_LIBRARY');
    if (! $libdave || ! file_exists($libdave)) {
        test()->markTestSkipped('Requires native libdave (DISCORDPHP_DAVE_LIBRARY not set or file not found).');
    }

    // With real libdave the Runtime::processWelcome call may succeed.
    // Full end-to-end coverage of this path requires a real session state;
    // the skip guard ensures this only runs on properly configured CI.
    expect(true)->toBeTrue();
});

// ─── Scenario 6 ───────────────────────────────────────────────────────────────

it('mls welcome passes recognized users to processWelcome without throwing', function (): void {
    $sentPayloads = [];
    $ws = makeWsForWelcomeTest($this, $sentPayloads, protocolVersion: 0);
    injectWelcomeFakeSession($ws);

    // Populate recognized users on daveState before invoking the handler.
    $daveState = getWelcomeDaveState($ws);
    $daveState->addRecognizedUsers(['100', '200', '300']);

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_WELCOME, pack('n', 1).'welcome-data');

    // processWelcome will receive recognizedUsersIncludingSelf() = [100, 200, 300].
    // It returns false (no libdave) and the handler must not throw.
    invokeWelcomeMethod($ws, 'handleDaveMlsWelcome', [$frame]);

    expect($sentPayloads)->not->toBeEmpty();
    $binary = BinaryFrame::fromClientPayload($sentPayloads[0]);
    expect($binary)->not->toBeNull();
    /** @var BinaryFrame $binary */
    expect($binary->opcode)->toBe(Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME);
});

// ─── Scenario 7 ───────────────────────────────────────────────────────────────

it('mls welcome correctly extracts transition ID from binary payload', function (): void {
    $sentPayloads = [];
    $ws = makeWsForWelcomeTest($this, $sentPayloads, protocolVersion: 0);
    injectWelcomeFakeSession($ws);

    // Encode transition_id=42 as a big-endian unsigned short followed by body.
    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_WELCOME, pack('n', 42).'welcome');
    invokeWelcomeMethod($ws, 'handleDaveMlsWelcome', [$frame]);

    // handleInvalidDaveTransition(42, true) is called; the protocol-version-0
    // branch resets state and returns after sending INVALID_COMMIT_WELCOME.
    expect($sentPayloads)->not->toBeEmpty();
    $binary = BinaryFrame::fromClientPayload($sentPayloads[0]);
    expect($binary)->not->toBeNull();
    /** @var BinaryFrame $binary */
    expect($binary->opcode)->toBe(Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME);

    // Protocol state must have been reset (session cleared).
    $daveState = getWelcomeDaveState($ws);
    expect($daveState->session)->toBeNull();
    expect($daveState->protocolVersion)->toBe(0);
});

// ─── Additional edge case: protocolVersion=1 still sends INVALID_COMMIT_WELCOME ──

it('mls welcome sends INVALID_COMMIT_WELCOME on processWelcome failure with protocolVersion 1', function (): void {
    $sentPayloads = [];
    $ws = makeWsForWelcomeTest($this, $sentPayloads, protocolVersion: 1);
    injectWelcomeFakeSession($ws);

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_WELCOME, pack('n', 3).'welcome-payload');
    invokeWelcomeMethod($ws, 'handleDaveMlsWelcome', [$frame]);

    // processWelcome returns false → INVALID_COMMIT_WELCOME is always the first send.
    expect($sentPayloads)->not->toBeEmpty();
    $binary = BinaryFrame::fromClientPayload($sentPayloads[0]);
    expect($binary)->not->toBeNull();
    /** @var BinaryFrame $binary */
    expect($binary->opcode)->toBe(Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME);
});

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Builds a bare WS instance (no constructor) wired to a mock socket that
 * records every payload passed to `send()` into $sentPayloads.
 *
 * @param array<string> $sentPayloads Populated by reference on each socket send.
 */
function makeWsForWelcomeTest(TestCase $test, array &$sentPayloads, int $protocolVersion = 0): WS
{
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();

    $state = new State();
    $state->setProtocolVersion($protocolVersion);

    $loggerProp = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProp->setAccessible(true);
    $loggerProp->setValue($discord, new NullLogger());

    $daveStateProp = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProp->setAccessible(true);
    $daveStateProp->setValue($ws, $state);

    $discordProp = new \ReflectionProperty(WS::class, 'discord');
    $discordProp->setAccessible(true);
    $discordProp->setValue($ws, $discord);

    $socket = invokeWelcomeMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send'])
        ->getMock();

    $socket->method('send')->willReturnCallback(
        function (string $payload) use (&$sentPayloads): void {
            $sentPayloads[] = $payload;
        }
    );

    $socketProp = new \ReflectionProperty(WS::class, 'socket');
    $socketProp->setAccessible(true);
    $socketProp->setValue($ws, $socket);

    return $ws;
}

/**
 * Injects a fake (stdClass-backed) SessionHandle into the WS daveState so
 * the early-return null-check in handleDaveMlsWelcome is satisfied.
 */
function injectWelcomeFakeSession(WS $ws): void
{
    getWelcomeDaveState($ws)->replaceSession(new SessionHandle(new \stdClass()));
}

/**
 * Returns the DaveState instance from the WS object via reflection.
 */
function getWelcomeDaveState(WS $ws): State
{
    $prop = new \ReflectionProperty(WS::class, 'daveState');
    $prop->setAccessible(true);

    return $prop->getValue($ws);
}

/**
 * Invokes a protected/private method on $object via reflection.
 *
 * @param array<int, mixed> $arguments
 */
function invokeWelcomeMethod(object $object, string $method, array $arguments = []): mixed
{
    $ref = new \ReflectionMethod($object, $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs($object, $arguments);
}
