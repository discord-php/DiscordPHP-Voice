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
use Discord\Parts\Channel\Channel;
use Discord\Voice\Client;
use Discord\Voice\Client\WS;
use Discord\Voice\Dave\Runtime;
use Discord\Voice\Dave\State;
use Discord\WebSockets\Op;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ratchet\Client\WebSocket;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

afterEach(function (): void {
    Runtime::reset();
});

// ── Test 1: seq_ack is preserved after a non-critical close ───────────────

it('non-critical WS close preserves lastReceivedSequence in daveState', function (): void {
    $ws = makeWsForCloseTest($this);
    invokeCloseTestWsMethod($ws, 'recordGatewaySequence', [42]);

    $ws->handleClose(Op::CLOSE_VOICE_SERVER_CRASH, 'server crash');

    $state = getCloseTestDaveState($ws);
    expect($state->lastReceivedSequence)->toBe(42);
});

// ── Test 2: session_id is preserved after a non-critical close ─────────────

it('non-critical WS close preserves voice_sessions session id', function (): void {
    $discord = null;
    $ws = makeWsForCloseTest($this, discord: $discord);

    expect($discord->voice_sessions['guild-1'])->toBe('session-1');

    $ws->handleClose(Op::CLOSE_VOICE_SERVER_CRASH, 'server crash');

    expect($discord->voice_sessions['guild-1'])->not->toBeNull(
        'voice_sessions must remain set after a non-critical close so the reconnect can Resume'
    );
});

// ── Test 3: WS heartbeat timer is cancelled on close ───────────────────────

it('WS own heartbeat timer is cancelled when WebSocket closes', function (): void {
    $cancelledTimers = [];
    $ws = makeWsForCloseTest($this, cancelledTimers: $cancelledTimers);

    $heartbeatTimer = $this->getMockBuilder(TimerInterface::class)->getMock();

    $wsHeartbeatProp = new \ReflectionProperty(WS::class, 'heartbeat');
    $wsHeartbeatProp->setAccessible(true);
    $wsHeartbeatProp->setValue($ws, $heartbeatTimer);

    $ws->handleClose(Op::CLOSE_VOICE_SERVER_CRASH, 'server crash');

    expect($cancelledTimers)->toContain($heartbeatTimer);
});

// ── Test 4: vc->heartbeat IS correctly cancelled on close ─────────────────

it('vc heartbeat timer is cancelled when WebSocket closes', function (): void {
    $cancelledTimers = [];
    $ws = makeWsForCloseTest($this, cancelledTimers: $cancelledTimers);

    $vcHeartbeatTimer = $this->getMockBuilder(TimerInterface::class)->getMock();
    $ws->vc->heartbeat = $vcHeartbeatTimer;

    $ws->handleClose(Op::CLOSE_VOICE_SERVER_CRASH, 'server crash');

    expect($cancelledTimers)->toContain($vcHeartbeatTimer);
});

// ── Test 5: reconnect after resumable close sends Resume ───────────────────

it('reconnect after non-critical close sends Resume opcode 7 with seq_ack', function (): void {
    $sentPayloads = [];
    $discord = null;
    $ws = makeWsForCloseTest($this, discord: $discord, sentPayloads: $sentPayloads);

    invokeCloseTestWsMethod($ws, 'recordGatewaySequence', [55]);

    // Simulate an already-identified connection (sentLoginFrame = true).
    $sentLoginFrameProp = new \ReflectionProperty(WS::class, 'sentLoginFrame');
    $sentLoginFrameProp->setAccessible(true);
    $sentLoginFrameProp->setValue($ws, true);

    // Close with a resumable code; schedules vc->boot() via a mocked timer.
    $ws->handleClose(Op::CLOSE_VOICE_SERVER_CRASH, 'server crash');

    // Simulate what the scheduled reconnect timer callback does before
    // calling vc->boot() / creating a new WS.
    $ws->vc->reconnecting = true;
    $sentLoginFrameProp->setValue($ws, false);

    // Reset captured payloads — we only care about what the reconnect sends.
    $sentPayloads = [];

    // This mirrors what a real reconnect does: the new WS instance sends the
    // first frame while VoiceClient is marked as reconnecting.
    invokeCloseTestWsMethod($ws, 'handleSendingOfLoginFrame');

    expect($sentPayloads)->toHaveCount(1);
    $decoded = json_decode($sentPayloads[0], true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['op'])->toBe(
        Op::VOICE_RESUME,
        'Reconnect after a resumable close should send Resume (op 7), not Identify (op 0)'
    );
    expect($decoded['d'])->toHaveKey('seq_ack');
    expect($decoded['d']['seq_ack'])->toBe(55);
});

// ── Test 6: handleResume includes seq_ack even right after a non-critical close

it('handleResume sends op 7 with seq_ack regardless of voice_sessions state', function (): void {
    $sentPayloads = [];
    $ws = makeWsForCloseTest($this, sentPayloads: $sentPayloads);

    invokeCloseTestWsMethod($ws, 'recordGatewaySequence', [99]);

    // Close, then immediately call handleResume directly.
    // This verifies that the resume payload construction itself is correct;
    // the bug is only in whether handleResume() is ever *called* by the
    // reconnect decision logic after a close (see Tests 2 and 5).
    $ws->handleClose(Op::CLOSE_VOICE_SERVER_CRASH, 'server crash');
    $sentPayloads = [];

    invokeCloseTestWsMethod($ws, 'handleResume');

    expect($sentPayloads)->toHaveCount(1);
    $decoded = json_decode($sentPayloads[0], true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['op'])->toBe(Op::VOICE_RESUME);
    expect($decoded['d']['seq_ack'])->toBe(99);
});

// ── Helpers ────────────────────────────────────────────────────────────────

/**
 * Build a WS instance wired with a mock Discord, mock VoiceClient, mock
 * WebSocket, and a mock event loop.
 *
 * @param array<TimerInterface> $cancelledTimers Receives every timer passed to loop->cancelTimer()
 * @param array<string>         $sentPayloads    Receives every raw JSON string passed to socket->send()
 * @param Discord|null          $discord         Receives the Discord instance (output by reference)
 */
function makeWsForCloseTest(
    TestCase $test,
    array &$cancelledTimers = [],
    array &$sentPayloads = [],
    ?Discord &$discord = null,
): WS {
    Runtime::configureCallbacks(availabilityOverride: false);

    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
    $discordInstance = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $state = new State();

    // Logger
    $loggerProp = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProp->setAccessible(true);
    $loggerProp->setValue($discordInstance, new NullLogger());

    // voice_sessions (public property on Discord)
    $discordInstance->voice_sessions = ['guild-1' => 'session-1'];

    // Mock event loop — capture cancelTimer calls; silently swallow addTimer.
    $loop = invokeCloseTestWsMethod($test, 'getMockBuilder', [LoopInterface::class])
        ->getMock();
    $loop->method('cancelTimer')->willReturnCallback(
        function (TimerInterface $timer) use (&$cancelledTimers): void {
            $cancelledTimers[] = $timer;
        }
    );
    $loop->method('addTimer')->willReturn(null);

    $loopProp = new \ReflectionProperty(Discord::class, 'loop');
    $loopProp->setAccessible(true);
    $loopProp->setValue($discordInstance, $loop);

    // VoiceClient mock (only emit is mocked; properties use real defaults)
    $voiceClient = invokeCloseTestWsMethod($test, 'getMockBuilder', [Client::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['emit'])
        ->getMock();
    $voiceClient->method('emit')->willReturn(null);

    $channel = (new \ReflectionClass(Channel::class))->newInstanceWithoutConstructor();
    $attrProp = new \ReflectionProperty(Channel::class, 'attributes');
    $attrProp->setAccessible(true);
    $attrProp->setValue($channel, ['guild_id' => 'guild-1', 'id' => 'channel-1']);
    $voiceClient->channel = $channel;

    // WebSocket mock — capture send() calls; no-op close().
    $socket = invokeCloseTestWsMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send', 'close'])
        ->getMock();
    $socket->method('send')->willReturnCallback(
        function (string $payload) use (&$sentPayloads): void {
            $sentPayloads[] = $payload;
        }
    );
    $socket->method('close')->willReturn(null);

    // Inject all dependencies into WS via reflection
    $daveStateProp = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProp->setAccessible(true);
    $daveStateProp->setValue($ws, $state);

    $discordProp = new \ReflectionProperty(WS::class, 'discord');
    $discordProp->setAccessible(true);
    $discordProp->setValue($ws, $discordInstance);

    $socketProp = new \ReflectionProperty(WS::class, 'socket');
    $socketProp->setAccessible(true);
    $socketProp->setValue($ws, $socket);

    $dataProp = new \ReflectionProperty(WS::class, 'data');
    $dataProp->setAccessible(true);
    $dataProp->setValue($ws, ['token' => 'voice-token', 'user_id' => 'self-user']);

    $maxDaveProp = new \ReflectionProperty(WS::class, 'maxDaveProtocolVersion');
    $maxDaveProp->setAccessible(true);
    $maxDaveProp->setValue($ws, 2);

    $ws->vc = $voiceClient;
    $discord = $discordInstance;

    return $ws;
}

function getCloseTestDaveState(WS $ws): State
{
    $prop = new \ReflectionProperty(WS::class, 'daveState');
    $prop->setAccessible(true);

    return $prop->getValue($ws);
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeCloseTestWsMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}
