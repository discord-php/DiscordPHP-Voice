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
use Discord\Parts\Voice\UserConnected;
use Discord\Voice\Client;
use Discord\Voice\Client\WS;
use Discord\Voice\Dave\Runtime;
use Discord\Voice\Dave\State;
use Discord\WebSockets\Op;
use Discord\WebSockets\Payload;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ratchet\Client\WebSocket;

afterEach(function (): void {
    Runtime::reset();
});

// ── handleSendingOfLoginFrame ──────────────────────────────────────────────

it('identify payload includes max_dave_protocol_version', function (): void {
    $sentPayloads = [];
    $ws = makeWsForReconnectTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });

    setReconnectWsFlag($ws, 'sentLoginFrame', false);

    invokeReconnectWsMethod($ws, 'handleSendingOfLoginFrame');

    $jsonPayloads = array_values(array_filter($sentPayloads, fn (string $p) => str_starts_with($p, '{')));
    expect($jsonPayloads)->toHaveCount(1);
    $decoded = json_decode($jsonPayloads[0], true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['op'])->toBe(Op::VOICE_IDENTIFY);
    expect($decoded['d']['session_id'])->toBe('session-1');
    expect($decoded['d'])->toHaveKey('max_dave_protocol_version');
});

it('reconnecting login frame sends resume with seq_ack', function (): void {
    $sentPayloads = [];
    $ws = makeWsForReconnectTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });

    $ws->vc->reconnecting = true;
    invokeReconnectWsMethod($ws, 'recordGatewaySequence', [55]);

    invokeReconnectWsMethod($ws, 'handleSendingOfLoginFrame');

    $jsonPayloads = array_values(array_filter($sentPayloads, fn (string $p) => str_starts_with($p, '{')));
    expect($jsonPayloads)->toHaveCount(1);
    $decoded = json_decode($jsonPayloads[0], true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['op'])->toBe(Op::VOICE_RESUME);
    expect($decoded['d']['seq_ack'])->toBe(55);
});

it('identify is only sent once due to sentLoginFrame guard', function (): void {
    $sentPayloads = [];
    $ws = makeWsForReconnectTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });

    setReconnectWsFlag($ws, 'sentLoginFrame', false);

    invokeReconnectWsMethod($ws, 'handleSendingOfLoginFrame');
    invokeReconnectWsMethod($ws, 'handleSendingOfLoginFrame');

    $jsonPayloads = array_values(array_filter($sentPayloads, fn (string $p) => str_starts_with($p, '{')));
    expect($jsonPayloads)->toHaveCount(1);
});

// ── handleResume ───────────────────────────────────────────────────────────

it('resume payload includes max_dave_protocol_version', function (): void {
    $sentPayloads = [];
    $ws = makeWsForReconnectTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });

    invokeReconnectWsMethod($ws, 'recordGatewaySequence', [10]);
    invokeReconnectWsMethod($ws, 'handleResume');

    $jsonPayloads = array_values(array_filter($sentPayloads, fn (string $p) => str_starts_with($p, '{')));
    expect($jsonPayloads)->toHaveCount(1);
    $decoded = json_decode($jsonPayloads[0], true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['d'])->toHaveKey('max_dave_protocol_version');
});

it('resume payload includes seq_ack alongside max_dave_protocol_version', function (): void {
    $sentPayloads = [];
    $ws = makeWsForReconnectTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });

    invokeReconnectWsMethod($ws, 'recordGatewaySequence', [55]);
    invokeReconnectWsMethod($ws, 'handleResume');

    $jsonPayloads = array_values(array_filter($sentPayloads, fn (string $p) => str_starts_with($p, '{')));
    expect($jsonPayloads)->toHaveCount(1);
    $decoded = json_decode($jsonPayloads[0], true, flags: JSON_THROW_ON_ERROR);
    expect($decoded['d']['seq_ack'])->toBe(55);
    expect($decoded['d'])->toHaveKey('max_dave_protocol_version');
});

// ── handleResumed (op 9) ───────────────────────────────────────────────────

it('handleResumed does not crash with empty data', function (): void {
    $ws = makeWsForReconnectTest($this, fn (string $p) => null);

    $data = new Payload(Op::VOICE_RESUMED, []);
    invokeReconnectWsMethod($ws, 'handleResumed', [$data]);

    expect(true)->toBeTrue();
});

it('handleResumed updates daveState protocolVersion when dave_protocol_version field is present', function (): void {
    $ws = makeWsForReconnectTest($this, fn (string $p) => null);

    $state = getReconnectDaveState($ws);
    $state->protocolVersion = 99;

    $data = new Payload(Op::VOICE_RESUMED, ['dave_protocol_version' => 1]);
    invokeReconnectWsMethod($ws, 'handleResumed', [$data]);

    // resolveDaveProtocolVersion returns 0 without libdave, or 1 with it; either way, not 99
    expect($state->protocolVersion)->not->toBe(99);
    expect($state->protocolVersion)->toBeLessThanOrEqual(1);
});

// ── handleClientConnect ────────────────────────────────────────────────────

it('handleClientConnect adds user_ids to daveState recognized users', function (): void {
    $ws = makeWsForReconnectTest($this, fn (string $p) => null);

    $data = new Payload(Op::VOICE_CLIENT_CONNECT, ['user_ids' => ['user-aaa', 'user-bbb']]);
    invokeReconnectWsMethod($ws, 'handleClientConnect', [$data]);

    $state = getReconnectDaveState($ws);
    expect($state->recognizedUsers())->toContain('user-aaa');
    expect($state->recognizedUsers())->toContain('user-bbb');
});

it('handleClientConnect handles non-array user_ids gracefully without adding users', function (): void {
    $ws = makeWsForReconnectTest($this, fn (string $p) => null);

    $data = new Payload(Op::VOICE_CLIENT_CONNECT, ['user_ids' => null]);
    invokeReconnectWsMethod($ws, 'handleClientConnect', [$data]);

    $state = getReconnectDaveState($ws);
    expect($state->recognizedUsers())->toBeEmpty();
});

// ── handleClientDisconnect ─────────────────────────────────────────────────

it('handleClientDisconnect removes the user from daveState recognized users', function (): void {
    $ws = makeWsForReconnectTest($this, fn (string $p) => null);

    $state = getReconnectDaveState($ws);
    $state->addRecognizedUsers(['user-xyz']);
    expect($state->recognizedUsers())->toContain('user-xyz');

    $data = new Payload(Op::VOICE_CLIENT_DISCONNECT, ['user_id' => 'user-xyz']);
    invokeReconnectWsMethod($ws, 'handleClientDisconnect', [$data]);

    expect($state->recognizedUsers())->not->toContain('user-xyz');
});

it('handleClientDisconnect does not crash when the user is not in recognized users', function (): void {
    $ws = makeWsForReconnectTest($this, fn (string $p) => null);

    $data = new Payload(Op::VOICE_CLIENT_DISCONNECT, ['user_id' => 'unknown-999']);
    invokeReconnectWsMethod($ws, 'handleClientDisconnect', [$data]);

    expect(true)->toBeTrue();
});

// ── helpers ────────────────────────────────────────────────────────────────

/**
 * @param callable(string): void $sendHook
 */
function makeWsForReconnectTest(TestCase $test, callable $sendHook): WS
{
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();

    $discord = invokeReconnectWsMethod($test, 'getMockBuilder', [Discord::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['factory', 'getFactory', 'getLogger'])
        ->getMock();

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, new NullLogger());

    $discord->voice_sessions = ['guild-1' => 'session-1'];

    $discord->method('getLogger')->willReturn(new NullLogger());
    $discord->method('factory')->willReturn(new \stdClass());

    $factoryMock = invokeReconnectWsMethod($test, 'getMockBuilder', [Factory::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['part'])
        ->getMock();

    $partMock = invokeReconnectWsMethod($test, 'getMockBuilder', [UserConnected::class])
        ->disableOriginalConstructor()
        ->getMock();

    $factoryMock->method('part')->willReturn($partMock);
    $discord->method('getFactory')->willReturn($factoryMock);

    $state = new State();

    $voiceClient = invokeReconnectWsMethod($test, 'getMockBuilder', [Client::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['emit'])
        ->getMock();
    $voiceClient->method('emit')->willReturn(null);
    $voiceClient->sentLoginFrame = false;
    $voiceClient->clientsConnected = [];

    $channel = (new \ReflectionClass(Channel::class))->newInstanceWithoutConstructor();
    $attributesProperty = new \ReflectionProperty(Channel::class, 'attributes');
    $attributesProperty->setAccessible(true);
    $attributesProperty->setValue($channel, ['guild_id' => 'guild-1', 'id' => 'channel-1']);
    $voiceClient->channel = $channel;

    $socket = invokeReconnectWsMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send'])
        ->getMock();
    $socket->method('send')->willReturnCallback(function (mixed $payload) use ($sendHook): void {
        $sendHook(payloadFromReconnectSend($payload));
    });

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

    $sentLoginProperty = new \ReflectionProperty(WS::class, 'sentLoginFrame');
    $sentLoginProperty->setAccessible(true);
    $sentLoginProperty->setValue($ws, false);

    $ws->vc = $voiceClient;

    return $ws;
}

function getReconnectDaveState(WS $ws): State
{
    $prop = new \ReflectionProperty(WS::class, 'daveState');
    $prop->setAccessible(true);

    return $prop->getValue($ws);
}

function payloadFromReconnectSend(mixed $payload): string
{
    return $payload instanceof \Ratchet\RFC6455\Messaging\Frame
        ? $payload->getPayload()
        : $payload;
}

function setReconnectWsFlag(WS $ws, string $property, mixed $value): void
{
    $prop = new \ReflectionProperty(WS::class, $property);
    $prop->setAccessible(true);
    $prop->setValue($ws, $value);
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeReconnectWsMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}
