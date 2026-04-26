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

// ── Test 2: session_id is preserved after a non-critical close (bug) ──────
//
// handleClose() unconditionally sets voice_sessions[guild_id] = null even for
// resumable close codes such as 4015. This prevents the reconnect path in
// handleConnection() from reaching the Resume (op 7) branch, because that
// branch requires voice_sessions[guild_id] to be set.
//
// Expected behaviour: preserved for resumable closes.
// Actual behaviour:   always cleared → reconnect sends Identify (op 0).

it('non-critical WS close preserves voice_sessions session id', function (): void {
    $discord = null;
    $ws = makeWsForCloseTest($this, discord: $discord);

    expect($discord->voice_sessions['guild-1'])->toBe('session-1');

    $ws->handleClose(Op::CLOSE_VOICE_SERVER_CRASH, 'server crash');

    // Production bug: handleClose sets voice_sessions[guild_id] = null for ALL
    // close codes, including resumable ones such as 4015. A correct
    // implementation would only clear the session for critical (non-resumable)
    // codes and preserve it otherwise so the reconnect can send Resume.
    expect($discord->voice_sessions['guild-1'])->not->toBeNull(
        'voice_sessions must remain set after a non-critical close so the reconnect can Resume'
    );
});

// ── Test 3: WS heartbeat timer is cancelled on close (bug) ────────────────
//
// WS::handleHello() stores the periodic heartbeat timer in $this->heartbeat
// (the WS property, TimerInterface). WS::handleClose() only cancels
// $this->vc->heartbeat (the VoiceClient property). It never calls
// cancelTimer() on $this->heartbeat, so that timer keeps firing after the
// connection is gone — a CPU and memory leak.
//
// Expected behaviour: $this->heartbeat is cancelled in handleClose().
// Actual behaviour:   it is not cancelled → timer leaks.

it('WS own heartbeat timer is cancelled when WebSocket closes', function (): void {
    $cancelledTimers = [];
    $ws = makeWsForCloseTest($this, cancelledTimers: $cancelledTimers);

    $heartbeatTimer = $this->getMockBuilder(TimerInterface::class)->getMock();

    $wsHeartbeatProp = new \ReflectionProperty(WS::class, 'heartbeat');
    $wsHeartbeatProp->setAccessible(true);
    $wsHeartbeatProp->setValue($ws, $heartbeatTimer);

    $ws->handleClose(Op::CLOSE_VOICE_SERVER_CRASH, 'server crash');

    // Production bug: handleClose() does not call loop->cancelTimer() on
    // $this->heartbeat (the WS periodic timer). Only $this->vc->heartbeat is
    // cancelled. The assertion below therefore fails with current code.
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

// ── Test 5: reconnect after resumable close cannot send Resume (bug) ──────
//
// After handleClose(4015) the voice_sessions entry is null, so even if the
// same WS instance's socket were reconnected with sentLoginFrame=true, the
// elseif branch in handleConnection() would evaluate to false and the code
// would fall through to handleSendingOfLoginFrame() (Identify, op 0) instead
// of handleResume() (Resume, op 7).
//
// This test asserts the DESIRED end state: the reconnect op-code should be 7
// (Resume) with the recorded seq_ack. It will fail with current production
// code because:
//   • voice_sessions is cleared by handleClose (see Test 2), and
//   • WS sends Identify when voice_sessions is absent.

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

    // Simulate what the scheduled reconnect timer callback does: it resets
    // sentLoginFrame to false before calling vc->boot() / creating a new WS.
    $sentLoginFrameProp->setValue($ws, false);

    // Reset captured payloads — we only care about what the reconnect sends.
    $sentPayloads = [];

    // With sentLoginFrame now false, handleSendingOfLoginFrame() will actually
    // send a payload. This mirrors what a real reconnect does: the new WS
    // instance (sentLoginFrame=false) sends the first frame on connect.
    //
    // A correct implementation would preserve voice_sessions so the
    // handleConnection() elseif branch fires and handleResume() (op 7) is
    // called. Because the production code clears voice_sessions in handleClose,
    // the reconnect ends up calling handleSendingOfLoginFrame() (op 0).
    invokeCloseTestWsMethod($ws, 'handleSendingOfLoginFrame');

    expect($sentPayloads)->toHaveCount(1);
    $decoded = json_decode($sentPayloads[0], true, flags: JSON_THROW_ON_ERROR);

    // Production bug: because voice_sessions was cleared by handleClose, the
    // reconnect uses Identify (op 0) instead of Resume (op 7). The assertion
    // below fails with current code, clearly documenting the broken behaviour.
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
