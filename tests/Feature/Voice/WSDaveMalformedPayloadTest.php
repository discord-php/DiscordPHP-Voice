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

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * @param callable(string): void $sendHook
 */
function makeWsForMalformedPayloadTest(TestCase $test, callable $sendHook, int $protocolVersion = 0): WS
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

    $socket = invokeMalformedPayloadMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send'])
        ->getMock();
    $socket->method('send')->willReturnCallback($sendHook);

    $socketProperty = new \ReflectionProperty(WS::class, 'socket');
    $socketProperty->setAccessible(true);
    $socketProperty->setValue($ws, $socket);

    return $ws;
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeMalformedPayloadMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}

function injectFakeSessionForMalformedTest(WS $ws): SessionHandle
{
    $daveStateProperty = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProperty->setAccessible(true);
    $state = $daveStateProperty->getValue($ws);

    $fakeSession = new SessionHandle(new \stdClass());
    $state->replaceSession($fakeSession);

    return $fakeSession;
}

// ─── 1: Empty payload to handleDaveMlsCommitWelcome ───────────────────────────

it('empty payload to handleDaveMlsCommitWelcome does not throw when session is null', function (): void {
    $ws = makeWsForMalformedPayloadTest($this, fn () => null);
    // session is null by default → guard early-returns without touching payload
    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_COMMIT_WELCOME, '');

    invokeMalformedPayloadMethod($ws, 'handleDaveMlsCommitWelcome', [$frame]);
    expect(true)->toBeTrue(); // reached here without exception
});

it('empty payload to handleDaveMlsCommitWelcome does not throw with active session', function (): void {
    Runtime::configureCallbacks(
        availabilityOverride: true,
        processCommitCallback: fn ($s, $p) => ['failed' => true],
        processWelcomeCallback: fn ($s, $p, $u) => false,
    );

    $sentPayloads = [];
    $ws = makeWsForMalformedPayloadTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });
    injectFakeSessionForMalformedTest($ws);

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_COMMIT_WELCOME, '');

    invokeMalformedPayloadMethod($ws, 'handleDaveMlsCommitWelcome', [$frame]);
    // Recovery frame was sent
    expect($sentPayloads)->not->toBeEmpty();
    $recoveryFrame = BinaryFrame::fromClientPayload($sentPayloads[0]);
    expect($recoveryFrame?->opcode)->toBe(Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME);
});

// ─── 2: 1-byte payload (too short for transition_id) to handleDaveMlsCommitWelcome

it('1-byte payload to handleDaveMlsCommitWelcome does not throw with active session', function (): void {
    Runtime::configureCallbacks(
        availabilityOverride: true,
        processCommitCallback: fn ($s, $p) => ['failed' => true],
        processWelcomeCallback: fn ($s, $p, $u) => false,
    );

    $sentPayloads = [];
    $ws = makeWsForMalformedPayloadTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });
    injectFakeSessionForMalformedTest($ws);

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_COMMIT_WELCOME, "\xFF");

    invokeMalformedPayloadMethod($ws, 'handleDaveMlsCommitWelcome', [$frame]);
    expect($sentPayloads)->not->toBeEmpty();
    $recoveryFrame = BinaryFrame::fromClientPayload($sentPayloads[0]);
    expect($recoveryFrame?->opcode)->toBe(Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME);
});

// ─── 3: Empty payload to handleDaveMlsAnnounceCommitTransition ────────────────

it('empty payload to handleDaveMlsAnnounceCommitTransition does not throw when session is null', function (): void {
    $ws = makeWsForMalformedPayloadTest($this, fn () => null);
    // session=null → guard early-returns
    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_ANNOUNCE_COMMIT_TRANSITION, '');

    invokeMalformedPayloadMethod($ws, 'handleDaveMlsAnnounceCommitTransition', [$frame]);
    expect(true)->toBeTrue(); // reached here without exception
});

it('empty payload to handleDaveMlsAnnounceCommitTransition does not throw with active session', function (): void {
    Runtime::configureCallbacks(
        availabilityOverride: true,
        processCommitCallback: fn ($s, $p) => ['failed' => true],
        processWelcomeCallback: fn ($s, $p, $u) => false,
    );

    $sentPayloads = [];
    $ws = makeWsForMalformedPayloadTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });
    injectFakeSessionForMalformedTest($ws);

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_ANNOUNCE_COMMIT_TRANSITION, '');

    invokeMalformedPayloadMethod($ws, 'handleDaveMlsAnnounceCommitTransition', [$frame]);
    expect($sentPayloads)->not->toBeEmpty();
    $recoveryFrame = BinaryFrame::fromClientPayload($sentPayloads[0]);
    expect($recoveryFrame?->opcode)->toBe(Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME);
});

// ─── 4: Empty payload to handleDaveMlsWelcome ─────────────────────────────────

it('empty payload to handleDaveMlsWelcome does not throw when session is null', function (): void {
    $ws = makeWsForMalformedPayloadTest($this, fn () => null);
    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_WELCOME, '');

    invokeMalformedPayloadMethod($ws, 'handleDaveMlsWelcome', [$frame]);
    expect(true)->toBeTrue(); // reached here without exception
});

it('empty payload to handleDaveMlsWelcome triggers invalid transition recovery without throwing', function (): void {
    Runtime::configureCallbacks(
        availabilityOverride: true,
        processCommitCallback: fn ($s, $p) => ['failed' => true],
        processWelcomeCallback: fn ($s, $p, $u) => false,
    );

    $sentPayloads = [];
    $ws = makeWsForMalformedPayloadTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });
    injectFakeSessionForMalformedTest($ws);

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_WELCOME, '');

    invokeMalformedPayloadMethod($ws, 'handleDaveMlsWelcome', [$frame]);
    // processWelcome returns false → handleInvalidDaveTransition → INVALID_COMMIT_WELCOME
    expect($sentPayloads)->not->toBeEmpty();
    $recoveryFrame = BinaryFrame::fromClientPayload($sentPayloads[0]);
    expect($recoveryFrame?->opcode)->toBe(Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME);
});

// ─── 5: Non-BinaryFrame (plain stdClass) to binary opcode handlers ─────────────

it('non-BinaryFrame to handleDaveMlsCommitWelcome does not throw', function (): void {
    $ws = makeWsForMalformedPayloadTest($this, fn () => null);
    injectFakeSessionForMalformedTest($ws);

    $notAFrame = (object) ['d' => ['transition_id' => 1]];

    invokeMalformedPayloadMethod($ws, 'handleDaveMlsCommitWelcome', [$notAFrame]);
    expect(true)->toBeTrue(); // instanceof guard prevented any payload parsing
});

it('non-BinaryFrame to handleDaveMlsAnnounceCommitTransition does not throw', function (): void {
    $ws = makeWsForMalformedPayloadTest($this, fn () => null);
    injectFakeSessionForMalformedTest($ws);

    $notAFrame = (object) ['d' => ['transition_id' => 1]];

    invokeMalformedPayloadMethod($ws, 'handleDaveMlsAnnounceCommitTransition', [$notAFrame]);
    expect(true)->toBeTrue(); // instanceof guard prevented any payload parsing
});

it('non-BinaryFrame to handleDaveMlsWelcome does not throw', function (): void {
    $ws = makeWsForMalformedPayloadTest($this, fn () => null);
    injectFakeSessionForMalformedTest($ws);

    $notAFrame = (object) ['d' => ['transition_id' => 1]];

    invokeMalformedPayloadMethod($ws, 'handleDaveMlsWelcome', [$notAFrame]);
    expect(true)->toBeTrue(); // instanceof guard prevented any payload parsing
});
