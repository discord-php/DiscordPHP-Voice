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

afterEach(function (): void {
    Runtime::reset();
});

// ── sendHeartbeat (op 3) ───────────────────────────────────────────────────

it('heartbeat payload uses voice heartbeat opcode and contains a timestamp', function (): void {
    $sentPayloads = [];
    $ws = makeWsForResumeTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });

    $ws->sendHeartbeat();

    expect($sentPayloads)->toHaveCount(1);
    $decoded = json_decode($sentPayloads[0], true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['op'])->toBe(Op::VOICE_HEARTBEAT);
    expect($decoded['op'])->toBe(3);
    expect($decoded['d'])->toHaveKey('t');
    expect($decoded['d']['t'])->toBeInt();
});

it('heartbeat omits seq_ack when no gateway sequence has been recorded', function (): void {
    $sentPayloads = [];
    $ws = makeWsForResumeTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });

    $ws->sendHeartbeat();

    expect($sentPayloads)->toHaveCount(1);
    $decoded = json_decode($sentPayloads[0], true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['d'])->not->toHaveKey('seq_ack');
});

it('heartbeat reflects the most recently recorded gateway sequence', function (): void {
    $sentPayloads = [];
    $ws = makeWsForResumeTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });

    invokeResumeWsMethod($ws, 'recordGatewaySequence', [10]);
    invokeResumeWsMethod($ws, 'recordGatewaySequence', [33]);
    $ws->sendHeartbeat();

    expect($sentPayloads)->toHaveCount(1);
    $decoded = json_decode($sentPayloads[0], true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['d']['seq_ack'])->toBe(33);
});

// ── handleResume (op 7) ────────────────────────────────────────────────────

it('resume payload uses voice resume opcode with server_id, session_id and token', function (): void {
    $sentPayloads = [];
    $ws = makeWsForResumeTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });

    invokeResumeWsMethod($ws, 'handleResume');

    expect($sentPayloads)->toHaveCount(1);
    $decoded = json_decode($sentPayloads[0], true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['op'])->toBe(Op::VOICE_RESUME);
    expect($decoded['op'])->toBe(7);
    expect($decoded['d']['server_id'])->toBe('guild-1');
    expect($decoded['d']['session_id'])->toBe('session-1');
    expect($decoded['d']['token'])->toBe('voice-token');
});

it('resume omits seq_ack when no gateway sequence has been recorded', function (): void {
    $sentPayloads = [];
    $ws = makeWsForResumeTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });

    invokeResumeWsMethod($ws, 'handleResume');

    expect($sentPayloads)->toHaveCount(1);
    $decoded = json_decode($sentPayloads[0], true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['d'])->not->toHaveKey('seq_ack');
});

// ── recordGatewaySequence ──────────────────────────────────────────────────

it('recordGatewaySequence updates daveState lastReceivedSequence to the provided value', function (): void {
    $ws = makeWsForResumeTest($this, fn (string $p) => null);
    $state = getResumeDaveState($ws);

    expect($state->lastReceivedSequence)->toBeNull();

    invokeResumeWsMethod($ws, 'recordGatewaySequence', [123]);

    expect($state->lastReceivedSequence)->toBe(123);
});

it('recordGatewaySequence with null does not clear an existing sequence', function (): void {
    $ws = makeWsForResumeTest($this, fn (string $p) => null);
    $state = getResumeDaveState($ws);

    invokeResumeWsMethod($ws, 'recordGatewaySequence', [50]);
    invokeResumeWsMethod($ws, 'recordGatewaySequence', [null]);

    expect($state->lastReceivedSequence)->toBe(50);
});

// ── reconnect / resume continuity ──────────────────────────────────────────

it('resume after multiple recorded sequences identifies with the most recent one', function (): void {
    $sentPayloads = [];
    $ws = makeWsForResumeTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });

    invokeResumeWsMethod($ws, 'recordGatewaySequence', [1]);
    invokeResumeWsMethod($ws, 'recordGatewaySequence', [2]);
    invokeResumeWsMethod($ws, 'recordGatewaySequence', [99]);

    invokeResumeWsMethod($ws, 'handleResume');

    expect($sentPayloads)->toHaveCount(1);
    $decoded = json_decode($sentPayloads[0], true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['op'])->toBe(Op::VOICE_RESUME);
    expect($decoded['d']['seq_ack'])->toBe(99);
});

it('heartbeat and subsequent resume both report the same recorded sequence', function (): void {
    $sentPayloads = [];
    $ws = makeWsForResumeTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });

    invokeResumeWsMethod($ws, 'recordGatewaySequence', [256]);
    $ws->sendHeartbeat();
    invokeResumeWsMethod($ws, 'handleResume');

    expect($sentPayloads)->toHaveCount(2);
    $heartbeat = json_decode($sentPayloads[0], true, flags: JSON_THROW_ON_ERROR);
    $resume = json_decode($sentPayloads[1], true, flags: JSON_THROW_ON_ERROR);
    expect($heartbeat['op'])->toBe(Op::VOICE_HEARTBEAT);
    expect($resume['op'])->toBe(Op::VOICE_RESUME);
    expect($heartbeat['d']['seq_ack'])->toBe(256);
    expect($resume['d']['seq_ack'])->toBe(256);
});

// ── helpers ────────────────────────────────────────────────────────────────

/**
 * @param callable(string): void $sendHook
 */
function makeWsForResumeTest(TestCase $test, callable $sendHook): WS
{
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $state = new State();

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, new NullLogger());

    $voiceSessionsProperty = new \ReflectionProperty(Discord::class, 'voice_sessions');
    $voiceSessionsProperty->setAccessible(true);
    $voiceSessionsProperty->setValue($discord, ['guild-1' => 'session-1']);

    $voiceClient = invokeResumeWsMethod($test, 'getMockBuilder', [Client::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['emit'])
        ->getMock();
    $voiceClient->method('emit')->willReturn(null);

    $channel = (new \ReflectionClass(Channel::class))->newInstanceWithoutConstructor();
    $attributesProperty = new \ReflectionProperty(Channel::class, 'attributes');
    $attributesProperty->setAccessible(true);
    $attributesProperty->setValue($channel, ['guild_id' => 'guild-1', 'id' => 'channel-1']);
    $voiceClient->channel = $channel;

    $socket = invokeResumeWsMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send'])
        ->getMock();
    $socket->method('send')->willReturnCallback($sendHook);

    $daveStateProperty = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProperty->setAccessible(true);
    $daveStateProperty->setValue($ws, $state);

    $discordProperty = new \ReflectionProperty(WS::class, 'discord');
    $discordProperty->setAccessible(true);
    $discordProperty->setValue($ws, $discord);

    $socketProperty = new \ReflectionProperty(WS::class, 'socket');
    $socketProperty->setAccessible(true);
    $socketProperty->setValue($ws, $socket);

    $dataProperty = new \ReflectionProperty(WS::class, 'data');
    $dataProperty->setAccessible(true);
    $dataProperty->setValue($ws, ['token' => 'voice-token', 'user_id' => 'self-user']);

    $maxDaveProperty = new \ReflectionProperty(WS::class, 'maxDaveProtocolVersion');
    $maxDaveProperty->setAccessible(true);
    $maxDaveProperty->setValue($ws, 2);

    $ws->vc = $voiceClient;

    return $ws;
}

function getResumeDaveState(WS $ws): State
{
    $prop = new \ReflectionProperty(WS::class, 'daveState');
    $prop->setAccessible(true);

    return $prop->getValue($ws);
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeResumeWsMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}
