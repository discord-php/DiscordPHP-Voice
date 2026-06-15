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
use Discord\Voice\Dave\GatewayCoordinator;
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

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * @param callable(string): void $sendHook
 */
function makeWsForErrorRecoveryTest(TestCase $test, callable $sendHook, int $protocolVersion = 0): WS
{
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $state = new State();
    $state->setProtocolVersion($protocolVersion);

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, new NullLogger());

    $daveStateProperty = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProperty->setAccessible(true);
    $daveStateProperty->setValue($ws, $state);

    $discordProperty = new \ReflectionProperty(WS::class, 'discord');
    $discordProperty->setAccessible(true);
    $discordProperty->setValue($ws, $discord);

    $socket = invokeErrorRecoveryMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send'])
        ->getMock();
    $socket->method('send')->willReturnCallback(function (mixed $payload) use ($sendHook): void {
        $sendHook(payloadFromErrorRecoverySend($payload));
    });

    $socketProperty = new \ReflectionProperty(WS::class, 'socket');
    $socketProperty->setAccessible(true);
    $socketProperty->setValue($ws, $socket);

    return $ws;
}

function getErrorRecoveryDaveState(WS $ws): State
{
    $prop = new \ReflectionProperty(WS::class, 'daveState');
    $prop->setAccessible(true);

    return $prop->getValue($ws);
}

function payloadFromErrorRecoverySend(mixed $payload): string
{
    return $payload instanceof \Ratchet\RFC6455\Messaging\Frame
        ? $payload->getPayload()
        : $payload;
}

/**
 * Extract the GatewayCoordinator from a WS instance.
 */
function getErrorRecoveryCoordinator(WS $ws): GatewayCoordinator
{
    $method = new \ReflectionMethod(WS::class, 'getCoordinator');
    $method->setAccessible(true);

    return $method->invoke($ws);
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeErrorRecoveryMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}

/**
 * @param list<string> $sentPayloads
 */
function assertSentBinaryOpcode(array $sentPayloads, int $expectedOpcode, int $index = 0): void
{
    expect($sentPayloads)->not->toBeEmpty();
    $frame = BinaryFrame::fromClientPayload($sentPayloads[$index]);
    expect($frame)->not->toBeNull();
    expect($frame->opcode)->toBe($expectedOpcode);
}

// ─── Scenario 1: handleInvalidDaveTransition sends Op 31 ──────────────────────

it('handleInvalidDaveTransition sends INVALID_COMMIT_WELCOME binary frame (Op 31)', function (): void {
    $sentPayloads = [];
    $ws = makeWsForErrorRecoveryTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    }, protocolVersion: 0);

    invokeErrorRecoveryMethod(getErrorRecoveryCoordinator($ws), 'handleInvalidDaveTransition', [42]);

    assertSentBinaryOpcode($sentPayloads, Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME);
});

// ─── Scenario 2: protocolVersion=0 → only INVALID_COMMIT_WELCOME sent ─────────

it('handleInvalidDaveTransition does not send key package when protocolVersion=0', function (): void {
    $sentPayloads = [];
    $ws = makeWsForErrorRecoveryTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    }, protocolVersion: 0);

    invokeErrorRecoveryMethod(getErrorRecoveryCoordinator($ws), 'handleInvalidDaveTransition', [7]);

    expect($sentPayloads)->toHaveCount(1);
    assertSentBinaryOpcode($sentPayloads, Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME);
});

// ─── Scenario 3: protocolVersion=1, regenerateKeyPackage=false → only 1 frame ─

it('handleInvalidDaveTransition does not send key package when regenerateKeyPackage=false', function (): void {
    $sentPayloads = [];
    // protocolVersion=1 but no libdave/missing identity → initializeDaveRuntimeState returns false
    $ws = makeWsForErrorRecoveryTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    }, protocolVersion: 1);

    invokeErrorRecoveryMethod(getErrorRecoveryCoordinator($ws), 'handleInvalidDaveTransition', [3, false]);

    // Only INVALID_COMMIT_WELCOME — no KEY_PACKAGE because runtime init failed
    expect($sentPayloads)->toHaveCount(1);
    assertSentBinaryOpcode($sentPayloads, Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME);
});

// ─── Scenario 4: State reset when protocolVersion=0 ───────────────────────────

it('handleInvalidDaveTransition resets DAVE state when protocolVersion=0', function (): void {
    $sentPayloads = [];
    $ws = makeWsForErrorRecoveryTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    }, protocolVersion: 0);

    $state = getErrorRecoveryDaveState($ws);
    // Place a non-null session handle to confirm it gets cleared
    $fakeSession = new SessionHandle(new \stdClass());
    $state->replaceSession($fakeSession);

    invokeErrorRecoveryMethod(getErrorRecoveryCoordinator($ws), 'handleInvalidDaveTransition', [99]);

    expect($state->protocolVersion)->toBe(0);
    expect($state->session)->toBeNull();
});

// ─── Scenario 5: protocolVersion=1, no libdave → no exception, state cleanly reset

it('handleInvalidDaveTransition resets state cleanly when protocolVersion=1 and no libdave', function (): void {
    $sentPayloads = [];
    $ws = makeWsForErrorRecoveryTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    }, protocolVersion: 1);

    // Must not throw, even without libdave available
    $threw = false;
    try {
        invokeErrorRecoveryMethod(getErrorRecoveryCoordinator($ws), 'handleInvalidDaveTransition', [1, true]);
    } catch (\Throwable) {
        $threw = true;
    }

    expect($threw)->toBeFalse();
    // INVALID_COMMIT_WELCOME was still sent
    expect($sentPayloads)->not->toBeEmpty();
    assertSentBinaryOpcode($sentPayloads, Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME);
});

// ─── Scenario 6: handleDaveMlsInvalidCommitWelcome cascades into recovery ─────

it('handleDaveMlsInvalidCommitWelcome triggers INVALID_COMMIT_WELCOME outbound', function (): void {
    $sentPayloads = [];
    $ws = makeWsForErrorRecoveryTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    }, protocolVersion: 0);

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME, 'arbitrary-data');
    invokeErrorRecoveryMethod($ws, 'handleDaveMlsInvalidCommitWelcome', [$frame]);

    expect($sentPayloads)->not->toBeEmpty();
    assertSentBinaryOpcode($sentPayloads, Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME);
});

// ─── Scenario 7: Op 31 frame wire format ──────────────────────────────────────

it('INVALID_COMMIT_WELCOME binary frame has correct wire format (opcode=31)', function (): void {
    $sentPayloads = [];
    $ws = makeWsForErrorRecoveryTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    }, protocolVersion: 0);

    invokeErrorRecoveryMethod(getErrorRecoveryCoordinator($ws), 'handleInvalidDaveTransition', [1]);

    expect($sentPayloads)->not->toBeEmpty();
    $frame = BinaryFrame::fromClientPayload($sentPayloads[0]);
    expect($frame)->not->toBeNull();
    expect($frame->opcode)->toBe(31);
    // INVALID_COMMIT_WELCOME carries no application payload
    expect($frame->payload)->toBe('');
});

// ─── Scenario 8: Op constant value equals 31 ──────────────────────────────────

it('VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME op constant is exactly 31', function (): void {
    expect(Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME)->toBe(31);
});
