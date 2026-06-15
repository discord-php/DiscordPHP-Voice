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
use Discord\Voice\Dave\State;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ratchet\Client\WebSocket;

/**
 * Generic WS builder for DAVE feature tests.
 *
 * Constructs a WS instance without running the real constructor, injects a
 * Discord stub with a NullLogger, a Dave\State (with a default identity of
 * user "42" in group 123 unless a custom state is supplied), and a WebSocket
 * mock whose send() calls are captured into $sentPayloads.
 *
 * @param array<int, string> $sentPayloads Reference array that collects raw send() payloads.
 */
function makeWsForTest(TestCase $test, array &$sentPayloads, ?State $state = null): WS
{
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();

    if ($state === null) {
        $state = new State();
        $state->setIdentity('42', 123);
    }

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, new NullLogger());

    $daveStateProperty = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProperty->setAccessible(true);
    $daveStateProperty->setValue($ws, $state);

    $discordProperty = new \ReflectionProperty(WS::class, 'discord');
    $discordProperty->setAccessible(true);
    $discordProperty->setValue($ws, $discord);

    $socket = invokeDaveMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send', 'close'])
        ->getMock();
    $socket->method('send')->willReturnCallback(function (mixed $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload instanceof \Ratchet\RFC6455\Messaging\Frame
            ? $payload->getPayload()
            : $payload;
    });

    $socketProperty = new \ReflectionProperty(WS::class, 'socket');
    $socketProperty->setAccessible(true);
    $socketProperty->setValue($ws, $socket);

    return $ws;
}

/**
 * Extract the Dave\State instance from a WS object via reflection.
 */
function getDaveStateFromWs(WS $ws): State
{
    $prop = new \ReflectionProperty(WS::class, 'daveState');
    $prop->setAccessible(true);

    return $prop->getValue($ws);
}

/**
 * Invoke any public or protected/private method on an object via reflection.
 *
 * @param array<int, mixed> $arguments
 */
function invokeDaveMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}
