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
use Discord\Voice\Dave\State;
use Discord\WebSockets\Op;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ratchet\Client\WebSocket;

it('sends voice identify on first connection even when voice session is populated', function (): void {
    $sentPayloads = [];
    $ws = makeWsForIdentifyResumeLoginFrameTest($this, $sentPayloads);

    $ws->handleSendingOfLoginFrame();

    expect($sentPayloads)->toHaveCount(1);
    $payload = decodeIdentifyResumeLoginFramePayload($sentPayloads[0]);

    expect($payload['op'])->toBe(Op::VOICE_IDENTIFY);
    expect($payload['d']['server_id'])->toBe('guild-1');
    expect($payload['d']['user_id'])->toBe('self-user');
    expect($payload['d']['session_id'])->toBe('voice-session-1');
    expect($payload['d']['token'])->toBe('voice-token');
    expect($payload['d']['max_dave_protocol_version'])->toBe(1);
    expect($payload['d'])->not->toHaveKey('seq_ack');
});

it('sends voice resume on true reconnect with session id and seq ack', function (): void {
    $sentPayloads = [];
    $ws = makeWsForIdentifyResumeLoginFrameTest($this, $sentPayloads, reconnecting: true);

    invokeIdentifyResumeLoginFrameWsMethod($ws, 'recordGatewaySequence', [4242]);
    $ws->handleSendingOfLoginFrame();

    expect($sentPayloads)->toHaveCount(1);
    $payload = decodeIdentifyResumeLoginFramePayload($sentPayloads[0]);

    expect($payload['op'])->toBe(Op::VOICE_RESUME);
    expect($payload['d']['server_id'])->toBe('guild-1');
    expect($payload['d']['session_id'])->toBe('voice-session-1');
    expect($payload['d']['token'])->toBe('voice-token');
    expect($payload['d']['max_dave_protocol_version'])->toBe(1);
    expect($payload['d']['seq_ack'])->toBe(4242);
});

// Helpers

/**
 * @param array<int, string> $sentPayloads
 */
function makeWsForIdentifyResumeLoginFrameTest(
    TestCase $test,
    array &$sentPayloads,
    bool $reconnecting = false,
): WS {
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $state = new State();

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, new NullLogger());

    $voiceSessionsProperty = new \ReflectionProperty(Discord::class, 'voice_sessions');
    $voiceSessionsProperty->setAccessible(true);
    $voiceSessionsProperty->setValue($discord, ['guild-1' => 'voice-session-1']);

    $voiceClient = invokeIdentifyResumeLoginFrameWsMethod($test, 'getMockBuilder', [Client::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['emit'])
        ->getMock();
    $voiceClient->method('emit')->willReturn(null);

    $channel = (new \ReflectionClass(Channel::class))->newInstanceWithoutConstructor();
    $attributesProperty = new \ReflectionProperty(Channel::class, 'attributes');
    $attributesProperty->setAccessible(true);
    $attributesProperty->setValue($channel, ['guild_id' => 'guild-1', 'id' => 'channel-1']);

    $voiceClient->channel = $channel;
    $voiceClient->reconnecting = $reconnecting;

    $socket = invokeIdentifyResumeLoginFrameWsMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send'])
        ->getMock();
    $socket->method('send')->willReturnCallback(
        function (string $payload) use (&$sentPayloads): void {
            $sentPayloads[] = $payload;
        }
    );

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
    $maxDaveProperty->setValue($ws, 1);

    $ws->vc = $voiceClient;

    return $ws;
}

/**
 * @return array{op:int,d:array<string,mixed>}
 */
function decodeIdentifyResumeLoginFramePayload(string $payload): array
{
    return json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeIdentifyResumeLoginFrameWsMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}
