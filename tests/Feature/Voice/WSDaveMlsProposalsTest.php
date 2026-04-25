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
use Discord\Voice\Dave\State;
use Discord\WebSockets\Op;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ratchet\Client\WebSocket;

afterEach(function (): void {
    Runtime::reset();
});

it('mls proposals send commit welcome when runtime builds payload', function (): void {
    Runtime::configureCallbacks(
        null,
        null,
        fn (string $payload, int $protocolVersion): ?string => "commit:{$protocolVersion}:{$payload}"
    );

    $sentPayload = null;
    $ws = makeWsForProposalsTest($this, function (string $payload) use (&$sentPayload): void {
        $sentPayload = $payload;
    });

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_PROPOSALS, 'proposals');
    invokeProtectedMethod($ws, 'handleDaveMlsProposals', [$frame]);

    expect($sentPayload)->toBeString();
    /** @var string $sentPayload */
    $out = BinaryFrame::fromClientPayload($sentPayload);
    expect($out)->not->toBeNull();
    /** @var BinaryFrame $out */
    expect($out->sequence)->toBeNull();
    expect($out->opcode)->toBe(Op::VOICE_DAVE_MLS_COMMIT_WELCOME);
    expect($out->payload)->toBe('commit:1:proposals');
});

it('mls proposals increment failure counter when runtime cannot build payload', function (): void {
    Runtime::configureCallbacks(null, null, fn (string $payload, int $protocolVersion): ?string => null);

    $sentPayload = null;
    [$ws, $state] = makeWsForProposalsTestWithState($this, function (string $payload) use (&$sentPayload): void {
        $sentPayload = $payload;
    });

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_PROPOSALS, 'proposals');
    invokeProtectedMethod($ws, 'handleDaveMlsProposals', [$frame]);

    // Nothing is sent — sending INVALID_COMMIT_WELCOME for proposal failures
    // triggers an infinite loop where Discord re-sends the same stale proposals.
    expect($sentPayload)->toBeNull();
    expect($state->proposalFailureCount)->toBe(1);
});

it('mls proposals close socket after 3 consecutive failures', function (): void {
    Runtime::configureCallbacks(null, null, fn (string $payload, int $protocolVersion): ?string => null);

    $closeCalled = false;
    [$ws] = makeWsForProposalsTestWithState($this, function (string $p): void {}, $closeCalled);

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_PROPOSALS, 'proposals');
    invokeProtectedMethod($ws, 'handleDaveMlsProposals', [$frame]);
    invokeProtectedMethod($ws, 'handleDaveMlsProposals', [$frame]);
    invokeProtectedMethod($ws, 'handleDaveMlsProposals', [$frame]);

    expect($closeCalled)->toBeTrue();
});

/**
 * @param callable(string): void $sendHook
 * @return array{WS, State}
 */
function makeWsForProposalsTestWithState(TestCase $test, callable $sendHook, bool &$closeCalled = false): array
{
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $state = new State();
    $state->setProtocolVersion(1);

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, new NullLogger());

    $daveStateProperty = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProperty->setAccessible(true);
    $daveStateProperty->setValue($ws, $state);

    $discordProperty = new \ReflectionProperty(WS::class, 'discord');
    $discordProperty->setAccessible(true);
    $discordProperty->setValue($ws, $discord);

    $socket = invokeProtectedMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send', 'close'])
        ->getMock();
    $socket->method('send')->willReturnCallback($sendHook);
    $socket->method('close')->willReturnCallback(function () use (&$closeCalled): void {
        $closeCalled = true;
    });

    $socketProperty = new \ReflectionProperty(WS::class, 'socket');
    $socketProperty->setAccessible(true);
    $socketProperty->setValue($ws, $socket);

    return [$ws, $state];
}

/**
 * @param callable(string): void $sendHook
 */
function makeWsForProposalsTest(TestCase $test, callable $sendHook): WS
{
    [$ws] = makeWsForProposalsTestWithState($test, $sendHook);

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
