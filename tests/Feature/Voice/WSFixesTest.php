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
use Discord\Factory\Factory;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Part;
use Discord\Parts\Voice\UserConnected;
use Discord\Voice\Client;
use Discord\Voice\Client\WS;
use Discord\Voice\Dave\Runtime;
use Discord\Voice\Dave\State;
use Discord\Voice\VoiceClient;
use Discord\WebSockets\Op;
use Discord\WebSockets\Payload;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ratchet\Client\WebSocket;

afterEach(function (): void {
    Runtime::reset();
});

// ---------------------------------------------------------------------------
// VULN-09: handleDaveTransitionReady missing transition ID guard
// ---------------------------------------------------------------------------

it('VULN-09: handleDaveTransitionReady does NOT apply encryptor when transition_id mismatches', function (): void {
    $ws = makeWsForFixesTest($this);
    $state = getFixesDaveState($ws);

    // Set pending transition ID to 5
    $state->prepareTransition(5);
    expect($state->pendingTransitionId)->toBe(5);

    // Fire DAVE_TRANSITION_READY with a mismatched transition_id
    $data = (object) ['d' => ['transition_id' => 99]];
    invokeFixesWsMethod($ws, 'handleDaveTransitionReady', [$data]);

    // The transition should NOT have been executed — pendingTransitionId stays at 5
    expect($state->pendingTransitionId)->toBe(5);
});

it('VULN-09: handleDaveTransitionReady executes transition when transition_id matches', function (): void {
    $ws = makeWsForFixesTest($this);
    $state = getFixesDaveState($ws);

    $state->prepareTransition(5);
    expect($state->pendingTransitionId)->toBe(5);

    $data = (object) ['d' => ['transition_id' => 5]];
    invokeFixesWsMethod($ws, 'handleDaveTransitionReady', [$data]);

    // Matching ID → transition is executed and pendingTransitionId is cleared
    expect($state->pendingTransitionId)->toBeNull();
});

// ---------------------------------------------------------------------------
// VULN-14: handleDaveMlsInvalidCommitWelcome hardcodes transition ID 0
// ---------------------------------------------------------------------------

it('VULN-14: handleDaveMlsInvalidCommitWelcome clears the correct pending transition', function (): void {
    $ws = makeWsForFixesTest($this);
    $state = getFixesDaveState($ws);

    $state->prepareTransition(7);
    expect($state->pendingTransitionId)->toBe(7);

    // Fire with transition_id matching the pending one
    $data = (object) ['d' => ['transition_id' => 7]];
    invokeFixesWsMethod($ws, 'handleDaveMlsInvalidCommitWelcome', [$data]);

    // The pending transition should have been cleaned up
    expect($state->pendingTransitionId)->toBeNull();
});

it('VULN-14: handleDaveMlsInvalidCommitWelcome clears pending transition even when transition_id is missing', function (): void {
    $ws = makeWsForFixesTest($this);
    $state = getFixesDaveState($ws);

    $state->prepareTransition(3);
    expect($state->pendingTransitionId)->toBe(3);

    // No transition_id key — defaults to 0, should not crash and should still reset state.
    $data = (object) ['d' => []];
    invokeFixesWsMethod($ws, 'handleDaveMlsInvalidCommitWelcome', [$data]);

    // resetProtocolState() always clears pendingTransitionId, regardless of the ID parameter.
    expect($state->pendingTransitionId)->toBeNull();
});

// ---------------------------------------------------------------------------
// VULN-15: Token not logged in debug output from handleSendingOfLoginFrame
// ---------------------------------------------------------------------------

it('VULN-15: handleSendingOfLoginFrame does not log the token value', function (): void {
    $capturedLogs = [];
    $capturingLogger = new class($capturedLogs) extends \Psr\Log\AbstractLogger {
        public function __construct(private array &$logs)
        {
        }

        public function log($level, string|\Stringable $message, array $context = []): void
        {
            $this->logs[] = json_encode(['level' => $level, 'msg' => (string) $message, 'ctx' => $context]);
        }
    };

    // Build a minimal Channel with guild_id set via the attributes array.
    $channel = (new \ReflectionClass(Channel::class))->newInstanceWithoutConstructor();
    $attrProp = new \ReflectionProperty(Part::class, 'attributes');
    $attrProp->setAccessible(true);
    $attrProp->setValue($channel, ['guild_id' => 'guild-test-123']);

    // Build a minimal VoiceClient (Client subclass) as vc.
    $vcMock = (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
    $channelProp = new \ReflectionProperty(VoiceClient::class, 'channel');
    $channelProp->setAccessible(true);
    $channelProp->setValue($vcMock, $channel);
    $vcMock->sentLoginFrame = false;

    // Build Discord with the capturing logger.
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $loggerProp = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProp->setAccessible(true);
    $loggerProp->setValue($discord, $capturingLogger);

    // WS is final — use real instance with a mock Ratchet socket so send() doesn't crash.
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();

    $mockSocket = invokeFixesWsMethod($this, 'getMockBuilder', [\Ratchet\Client\WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send'])
        ->getMock();
    $mockSocket->method('send')->willReturn(null);

    $socketProp = new \ReflectionProperty(WS::class, 'socket');
    $socketProp->setAccessible(true);
    $socketProp->setValue($ws, $mockSocket);

    $vcProp = new \ReflectionProperty(WS::class, 'vc');
    $vcProp->setAccessible(true);
    $vcProp->setValue($ws, $vcMock);

    $discordProp = new \ReflectionProperty(WS::class, 'discord');
    $discordProp->setAccessible(true);
    $discordProp->setValue($ws, $discord);

    $token = 'super-secret-voice-token';

    $dataProp = new \ReflectionProperty(WS::class, 'data');
    $dataProp->setAccessible(true);
    $dataProp->setValue($ws, ['user_id' => 'user-abc', 'token' => $token]);

    $sentLoginProp = new \ReflectionProperty(WS::class, 'sentLoginFrame');
    $sentLoginProp->setAccessible(true);
    $sentLoginProp->setValue($ws, false);

    $maxDaveProp = new \ReflectionProperty(WS::class, 'maxDaveProtocolVersion');
    $maxDaveProp->setAccessible(true);
    $maxDaveProp->setValue($ws, 0);

    $method = new \ReflectionMethod(WS::class, 'handleSendingOfLoginFrame');
    $method->setAccessible(true);
    $method->invoke($ws);

    $allLogs = implode(' ', $capturedLogs);
    expect($allLogs)->not->toContain($token);
});

// ---------------------------------------------------------------------------
// VULN-20: handleClientConnect replaces entire $vc->users array
// ---------------------------------------------------------------------------

it('VULN-20: second CLIENT_CONNECT event does not wipe users from first event', function (): void {
    $ws = makeWsForFixesRemoteTest($this);

    // Initialize the users array on the vc mock
    $ws->vc->users = [];

    // First event — user1
    invokeFixesWsMethod($ws, 'handleClientConnect', [
        new Payload(Op::VOICE_CLIENT_CONNECT, ['user_ids' => ['user1']]),
    ]);

    // Second event — user2
    invokeFixesWsMethod($ws, 'handleClientConnect', [
        new Payload(Op::VOICE_CLIENT_CONNECT, ['user_ids' => ['user2']]),
    ]);

    expect($ws->vc->users)->toHaveKey('user1')
        ->and($ws->vc->users)->toHaveKey('user2');
});

it('VULN-20: CLIENT_CONNECT updates existing user without removing others', function (): void {
    $ws = makeWsForFixesRemoteTest($this);

    $ws->vc->users = [];

    invokeFixesWsMethod($ws, 'handleClientConnect', [
        new Payload(Op::VOICE_CLIENT_CONNECT, ['user_ids' => ['userA', 'userB']]),
    ]);

    // userA reconnects — should update userA and keep userB
    invokeFixesWsMethod($ws, 'handleClientConnect', [
        new Payload(Op::VOICE_CLIENT_CONNECT, ['user_ids' => ['userA']]),
    ]);

    expect($ws->vc->users)->toHaveKey('userA')
        ->and($ws->vc->users)->toHaveKey('userB');
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Minimal WS instance wired with a Dave State, Discord (NullLogger), and mocked WebSocket.
 * Suitable for DAVE-only tests (no Factory/UserConnected).
 *
 * @param callable(string):void $sendHook
 */
function makeWsForFixesTest(TestCase $test, ?callable $sendHook = null): WS
{
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();

    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $loggerProp = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProp->setAccessible(true);
    $loggerProp->setValue($discord, new NullLogger());

    $state = new State();
    $state->setProtocolVersion(0);

    $daveStateProp = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProp->setAccessible(true);
    $daveStateProp->setValue($ws, $state);

    $discordProp = new \ReflectionProperty(WS::class, 'discord');
    $discordProp->setAccessible(true);
    $discordProp->setValue($ws, $discord);

    $sendHook ??= function (string $payload): void {
    };
    $socket = invokeFixesWsMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send'])
        ->getMock();
    $socket->method('send')->willReturnCallback($sendHook);

    $socketProp = new \ReflectionProperty(WS::class, 'socket');
    $socketProp->setAccessible(true);
    $socketProp->setValue($ws, $socket);

    return $ws;
}

/**
 * WS instance with Factory and UserConnected mocks for tests that exercise handleClientConnect.
 */
function makeWsForFixesRemoteTest(TestCase $test): WS
{
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();

    $discord = invokeFixesWsMethod($test, 'getMockBuilder', [Discord::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['getLogger', 'getFactory'])
        ->getMock();

    $loggerProp = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProp->setAccessible(true);
    $loggerProp->setValue($discord, new NullLogger());
    $discord->method('getLogger')->willReturn(new NullLogger());

    $partMock = invokeFixesWsMethod($test, 'getMockBuilder', [UserConnected::class])
        ->disableOriginalConstructor()
        ->getMock();

    $factoryMock = invokeFixesWsMethod($test, 'getMockBuilder', [Factory::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['part'])
        ->getMock();
    $factoryMock->method('part')->willReturn($partMock);

    $discord->method('getFactory')->willReturn($factoryMock);

    $vcMock = invokeFixesWsMethod($test, 'getMockBuilder', [Client::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['emit'])
        ->getMock();
    $vcMock->users = [];
    $vcMock->clientsConnected = [];

    $state = new State();

    $daveStateProp = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProp->setAccessible(true);
    $daveStateProp->setValue($ws, $state);

    $discordProp = new \ReflectionProperty(WS::class, 'discord');
    $discordProp->setAccessible(true);
    $discordProp->setValue($ws, $discord);

    $ws->vc = $vcMock;

    return $ws;
}

function getFixesDaveState(WS $ws): State
{
    $prop = new \ReflectionProperty(WS::class, 'daveState');
    $prop->setAccessible(true);

    return $prop->getValue($ws);
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeFixesWsMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}
