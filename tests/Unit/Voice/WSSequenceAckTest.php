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

final class WSSequenceAckTest extends TestCase
{
    public function testHeartbeatUsesLastReceivedGatewaySequence(): void
    {
        $sentPayload = null;
        $ws = $this->makeWs(function (string $payload) use (&$sentPayload): void {
            $sentPayload = $payload;
        });

        $this->invokePrivateMethod($ws, 'recordGatewaySequence', [42]);
        $ws->sendHeartbeat();

        self::assertIsString($sentPayload);
        /** @var string $sentPayload */

        $payload = json_decode($sentPayload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(42, $payload['d']['seq_ack']);
    }

    public function testResumeUsesLastReceivedGatewaySequence(): void
    {
        $sentPayload = null;
        $ws = $this->makeWs(function (string $payload) use (&$sentPayload): void {
            $sentPayload = $payload;
        });

        $this->invokePrivateMethod($ws, 'recordGatewaySequence', [77]);
        $this->invokeProtectedMethod($ws, 'handleResume');

        self::assertIsString($sentPayload);
        /** @var string $sentPayload */

        $payload = json_decode($sentPayload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(77, $payload['d']['seq_ack']);
    }

    public function testBinaryGatewayFramesUpdateSequenceAckBookkeeping(): void
    {
        $sentPayload = null;
        $ws = $this->makeWs(function (string $payload) use (&$sentPayload): void {
            $sentPayload = $payload;
        });

        $this->invokeProtectedMethod($ws, 'handleBinaryVoiceMessage', [(new BinaryFrame(91, 255, 'ignored'))->toPayload()]);
        $ws->sendHeartbeat();

        self::assertIsString($sentPayload);
        /** @var string $sentPayload */

        $payload = json_decode($sentPayload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(91, $payload['d']['seq_ack']);
    }

    /**
     * @param callable(string): void $sendHook
     */
    private function makeWs(callable $sendHook): WS
    {
        $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
        $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
        $state = new State();

        $voiceClient = $this->getMockBuilder(Client::class)
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

        $socket = $this->getMockBuilder(WebSocket::class)
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
    private function invokeProtectedMethod(object $object, string $method, array $arguments = []): mixed
    {
        $reflectionMethod = new \ReflectionMethod($object, $method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($object, $arguments);
    }

    /**
     * @param array<int, mixed> $arguments
     */
    private function invokePrivateMethod(object $object, string $method, array $arguments = []): mixed
    {
        return $this->invokeProtectedMethod($object, $method, $arguments);
    }
}
