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

namespace Discord\Tests\Unit\Voice;

use Discord\Discord;
use Discord\Parts\Channel\Channel;
use Discord\Voice\Client;
use Discord\Voice\Client\WS;
use Discord\Voice\Dave\BinaryFrame;
use Discord\Voice\Dave\State;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ratchet\Client\WebSocket;

it('heartbeat uses last received gateway sequence', function (): void {
    $sentPayload = null;
    $ws = makeWs($this, function (string $payload) use (&$sentPayload): void {
        $sentPayload = $payload;
    });

    invokePrivateMethod($ws, 'recordGatewaySequence', [42]);
    $ws->sendHeartbeat();

    expect($sentPayload)->toBeString();
    /** @var string $sentPayload */
    $payload = json_decode($sentPayload, true, flags: JSON_THROW_ON_ERROR);
    expect($payload['d']['seq_ack'])->toBe(42);
});

it('resume uses last received gateway sequence', function (): void {
    $sentPayload = null;
    $ws = makeWs($this, function (string $payload) use (&$sentPayload): void {
        $sentPayload = $payload;
    });

    invokePrivateMethod($ws, 'recordGatewaySequence', [77]);
    invokeProtectedMethod($ws, 'handleResume');

    expect($sentPayload)->toBeString();
    /** @var string $sentPayload */
    $payload = json_decode($sentPayload, true, flags: JSON_THROW_ON_ERROR);
    expect($payload['d']['seq_ack'])->toBe(77);
});

it('binary gateway frames update sequence ack bookkeeping', function (): void {
    $sentPayload = null;
    $ws = makeWs($this, function (string $payload) use (&$sentPayload): void {
        $sentPayload = $payload;
    });

    invokeProtectedMethod($ws, 'handleBinaryVoiceMessage', [(new BinaryFrame(91, 255, 'ignored'))->toPayload()]);
    $ws->sendHeartbeat();

    expect($sentPayload)->toBeString();
    /** @var string $sentPayload */
    $payload = json_decode($sentPayload, true, flags: JSON_THROW_ON_ERROR);
    expect($payload['d']['seq_ack'])->toBe(91);
});

/**
 * @param callable(string): void $sendHook
 */
function makeWs(TestCase $test, callable $sendHook): WS
{
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $state = new State();

    $voiceClient = invokeProtectedMethod($test, 'getMockBuilder', [Client::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['emit'])
        ->getMock();
    $channel = (new \ReflectionClass(Channel::class))->newInstanceWithoutConstructor();
    $attributesProperty = new \ReflectionProperty(Channel::class, 'attributes');
    $attributesProperty->setAccessible(true);
    $attributesProperty->setValue($channel, ['guild_id' => 'guild-1', 'id' => 'channel-1']);

    $voiceClient->channel = $channel;
    $voiceClient->method('emit')->willReturn(null);

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, new NullLogger());

    $voiceSessionsProperty = new \ReflectionProperty(Discord::class, 'voice_sessions');
    $voiceSessionsProperty->setAccessible(true);
    $voiceSessionsProperty->setValue($discord, ['guild-1' => 'session-1']);

    $socket = invokeProtectedMethod($test, 'getMockBuilder', [WebSocket::class])
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

    $ws->vc = $voiceClient;

    return $ws;
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeProtectedMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}

/**
 * @param array<int, mixed> $arguments
 */
function invokePrivateMethod(object $object, string $method, array $arguments = []): mixed
{
    return invokeProtectedMethod($object, $method, $arguments);
}
