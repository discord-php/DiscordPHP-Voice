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

it('returns early without sending when data is not a BinaryFrame', function (): void {
    $sentPayloads = [];
    $ws = makeWsForAnnounceCommitTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });

    invokeAnnounceCommitMethod($ws, 'handleDaveMlsAnnounceCommitTransition', [new \stdClass()]);

    expect($sentPayloads)->toBeEmpty();
});

it('returns early without sending when session is null', function (): void {
    $sentPayloads = [];
    $ws = makeWsForAnnounceCommitTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    }, protocolVersion: 1, session: null);

    $payload = pack('n', 42).'commit-data';
    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_ANNOUNCE_COMMIT_TRANSITION, $payload);
    invokeAnnounceCommitMethod($ws, 'handleDaveMlsAnnounceCommitTransition', [$frame]);

    expect($sentPayloads)->toBeEmpty();
});

it('sends INVALID_COMMIT_WELCOME when processCommit returns null (protocolVersion 0)', function (): void {
    $sentPayloads = [];
    $fakeSession = new SessionHandle(new \stdClass());
    $ws = makeWsForAnnounceCommitTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    }, protocolVersion: 0, session: $fakeSession);

    $payload = pack('n', 7).'some-commit-bytes';
    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_ANNOUNCE_COMMIT_TRANSITION, $payload);
    invokeAnnounceCommitMethod($ws, 'handleDaveMlsAnnounceCommitTransition', [$frame]);

    // processCommit fails (no libdave) → handleInvalidDaveTransition → INVALID_COMMIT_WELCOME
    // protocolVersion=0 → only INVALID_COMMIT_WELCOME is sent, no key package
    expect($sentPayloads)->toHaveCount(1);

    $decoded = BinaryFrame::fromClientPayload($sentPayloads[0]);
    expect($decoded)->not->toBeNull();
    /** @var BinaryFrame $decoded */
    expect($decoded->sequence)->toBeNull();
    expect($decoded->opcode)->toBe(Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME);
    expect($decoded->payload)->toBe('');
});

it('INVALID_COMMIT_WELCOME opcode value is 31', function (): void {
    $sentPayloads = [];
    $fakeSession = new SessionHandle(new \stdClass());
    $ws = makeWsForAnnounceCommitTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    }, protocolVersion: 0, session: $fakeSession);

    $payload = pack('n', 1).'bytes';
    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_ANNOUNCE_COMMIT_TRANSITION, $payload);
    invokeAnnounceCommitMethod($ws, 'handleDaveMlsAnnounceCommitTransition', [$frame]);

    expect($sentPayloads)->not->toBeEmpty();
    $raw = $sentPayloads[0];
    $opcodeFromRaw = unpack('Copcode', $raw[0])['opcode'];
    expect($opcodeFromRaw)->toBe(31);
});

it('resets protocol state on invalid commit when protocolVersion is 0', function (): void {
    $sentPayloads = [];
    $fakeSession = new SessionHandle(new \stdClass());
    $ws = makeWsForAnnounceCommitTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    }, protocolVersion: 0, session: $fakeSession);

    $payload = pack('n', 3).'bad-commit';
    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_ANNOUNCE_COMMIT_TRANSITION, $payload);
    invokeAnnounceCommitMethod($ws, 'handleDaveMlsAnnounceCommitTransition', [$frame]);

    // After handleInvalidDaveTransition with protocolVersion=0, state is reset
    $daveStateProp = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProp->setAccessible(true);
    /** @var State $daveState */
    $daveState = $daveStateProp->getValue($ws);
    expect($daveState->protocolVersion)->toBe(0);
    expect($daveState->session)->toBeNull();
});

it('sends INVALID_COMMIT_WELCOME when processCommit returns null (protocolVersion 1, no libdave)', function (): void {
    $sentPayloads = [];
    $fakeSession = new SessionHandle(new \stdClass());
    $ws = makeWsForAnnounceCommitTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    }, protocolVersion: 1, session: $fakeSession);

    Runtime::configureCallbacks(processCommitCallback: fn (?SessionHandle $s, string $c): ?array => null);

    $payload = pack('n', 5).'commit-payload';
    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_ANNOUNCE_COMMIT_TRANSITION, $payload);
    invokeAnnounceCommitMethod($ws, 'handleDaveMlsAnnounceCommitTransition', [$frame]);

    // processCommit fails (no libdave) → handleInvalidDaveTransition called
    // INVALID_COMMIT_WELCOME is always sent first
    expect($sentPayloads)->not->toBeEmpty();

    $decoded = BinaryFrame::fromClientPayload($sentPayloads[0]);
    expect($decoded)->not->toBeNull();
    /** @var BinaryFrame $decoded */
    expect($decoded->opcode)->toBe(Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME);
});

it('executes transition zero locally after a successful MLS announce commit', function (): void {
    $sentPayloads = [];
    $fakeSession = new SessionHandle(new \stdClass());
    $ws = makeWsForAnnounceCommitTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    }, protocolVersion: 0, session: $fakeSession);

    $state = getAnnounceCommitDaveState($ws);
    $state->prepareProtocolVersion(1);
    Runtime::configureCallbacks(processCommitCallback: fn (?SessionHandle $s, string $c): ?array => [
        'failed' => false,
        'ignored' => false,
    ]);

    $payload = pack('n', 0).'commit-payload';
    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_ANNOUNCE_COMMIT_TRANSITION, $payload);
    invokeAnnounceCommitMethod($ws, 'handleDaveMlsAnnounceCommitTransition', [$frame]);

    expect($sentPayloads)->toBeEmpty()
        ->and($state->pendingTransitionId)->toBeNull()
        ->and($state->protocolVersion)->toBe(1)
        ->and($state->passthroughMode)->toBeFalse();
});

it('handles empty commit payload gracefully (transitionId defaults to 0)', function (): void {
    $sentPayloads = [];
    $fakeSession = new SessionHandle(new \stdClass());
    $ws = makeWsForAnnounceCommitTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    }, protocolVersion: 0, session: $fakeSession);

    // BinaryFrame with empty payload — transition id defaults to 0
    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_ANNOUNCE_COMMIT_TRANSITION, '');
    invokeAnnounceCommitMethod($ws, 'handleDaveMlsAnnounceCommitTransition', [$frame]);

    expect($sentPayloads)->toHaveCount(1);
    $decoded = BinaryFrame::fromClientPayload($sentPayloads[0]);
    expect($decoded?->opcode)->toBe(Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME);
});

/**
 * @param callable(string): void $sendHook
 */
function makeWsForAnnounceCommitTest(
    TestCase $test,
    callable $sendHook,
    int $protocolVersion = 0,
    ?SessionHandle $session = null,
): WS {
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, new NullLogger());

    $state = new State();
    $state->setProtocolVersion($protocolVersion);

    if ($session !== null) {
        $sessionProperty = new \ReflectionProperty(State::class, 'session');
        $sessionProperty->setAccessible(true);
        $sessionProperty->setValue($state, $session);
    }

    $daveStateProperty = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProperty->setAccessible(true);
    $daveStateProperty->setValue($ws, $state);

    $discordProperty = new \ReflectionProperty(WS::class, 'discord');
    $discordProperty->setAccessible(true);
    $discordProperty->setValue($ws, $discord);

    $socket = invokeAnnounceCommitMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send'])
        ->getMock();
    $socket->method('send')->willReturnCallback(function (mixed $payload) use ($sendHook): void {
        $sendHook(payloadFromAnnounceCommitSend($payload));
    });

    $socketProperty = new \ReflectionProperty(WS::class, 'socket');
    $socketProperty->setAccessible(true);
    $socketProperty->setValue($ws, $socket);

    return $ws;
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeAnnounceCommitMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}

function payloadFromAnnounceCommitSend(mixed $payload): string
{
    return $payload instanceof \Ratchet\RFC6455\Messaging\Frame
        ? $payload->getPayload()
        : $payload;
}

function getAnnounceCommitDaveState(WS $ws): State
{
    $reflectionProperty = new \ReflectionProperty(WS::class, 'daveState');
    $reflectionProperty->setAccessible(true);

    /** @var State */
    return $reflectionProperty->getValue($ws);
}
