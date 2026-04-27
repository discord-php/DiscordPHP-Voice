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
use Discord\Voice\Dave\SessionHandle;
use Discord\Voice\Dave\State;
use Discord\WebSockets\Op;
use Discord\WebSockets\VoicePayload;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ratchet\Client\WebSocket;

afterEach(function (): void {
    Runtime::reset();
});

it('handleDaveTransitionReady executes the pending transition', function (): void {
    $ws = makeWsForStubHandlersTest($this, function (): void {
    });

    $state = getWsDaveState($ws);
    $state->prepareTransition(5);

    expect($state->pendingTransitionId)->toBe(5);

    $payload = VoicePayload::new(Op::VOICE_DAVE_TRANSITION_READY, ['transition_id' => 5]);
    invokeWsMethod($ws, 'handleDaveTransitionReady', [$payload]);

    expect($state->pendingTransitionId)->toBeNull();
});

it('handleDaveMlsKeyPackage accepts binary frame passively without sending', function (): void {
    $sends = [];
    $ws = makeWsForStubHandlersTest($this, function (string $payload) use (&$sends): void {
        $sends[] = $payload;
    });

    $state = getWsDaveState($ws);
    $initialVersion = $state->protocolVersion;

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_KEY_PACKAGE, 'key-package-bytes');
    invokeWsMethod($ws, 'handleDaveMlsKeyPackage', [$frame]);

    expect($sends)->toBeEmpty()
        ->and($state->externalSenderPackage)->toBeNull()
        ->and($state->protocolVersion)->toBe($initialVersion);
});

it('handleDaveMlsCommitWelcome returns early when session is null', function (): void {
    $sends = [];
    $ws = makeWsForStubHandlersTest($this, function (string $payload) use (&$sends): void {
        $sends[] = $payload;
    });

    // session is null by default in State
    $transitionPayload = pack('n', 3).'commit-welcome-bytes';
    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_COMMIT_WELCOME, $transitionPayload);
    invokeWsMethod($ws, 'handleDaveMlsCommitWelcome', [$frame]);

    expect($sends)->toBeEmpty();
});

it('handleDaveMlsCommitWelcome sends invalid commit welcome when both commit and welcome fail', function (): void {
    $sentPayloads = [];
    $ws = makeWsForStubHandlersTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });

    // Inject a non-null fake session (FFI unavailable → processCommit returns null, processWelcome returns false)
    $state = getWsDaveState($ws);
    $fakeSession = new SessionHandle(new \stdClass());
    $state->replaceSession($fakeSession);

    Runtime::configureCallbacks(processCommitCallback: fn (?SessionHandle $s, string $c): ?array => null, processWelcomeCallback: fn (?SessionHandle $s, string $w, array $u): bool => false);

    $transitionPayload = pack('n', 3).'commit-welcome-bytes';
    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_COMMIT_WELCOME, $transitionPayload);
    invokeWsMethod($ws, 'handleDaveMlsCommitWelcome', [$frame]);

    // handleInvalidDaveTransition → sendDaveInvalidCommitWelcome
    expect($sentPayloads)->not->toBeEmpty();
    $out = BinaryFrame::fromClientPayload($sentPayloads[0]);
    expect($out)->not->toBeNull();
    /** @var BinaryFrame $out */
    expect($out->opcode)->toBe(Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME);
});

it('handleDaveMlsInvalidCommitWelcome triggers invalid commit welcome send', function (): void {
    $sentPayloads = [];
    $ws = makeWsForStubHandlersTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });

    // protocolVersion=0 (default), session=null → handleInvalidDaveTransition resets and returns early after sending
    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME, '');
    invokeWsMethod($ws, 'handleDaveMlsInvalidCommitWelcome', [$frame]);

    expect($sentPayloads)->not->toBeEmpty();
    $out = BinaryFrame::fromClientPayload($sentPayloads[0]);
    expect($out)->not->toBeNull();
    /** @var BinaryFrame $out */
    expect($out->opcode)->toBe(Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME);
});

/**
 * @param callable(string): void $sendHook
 */
function makeWsForStubHandlersTest(TestCase $test, callable $sendHook): WS
{
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $state = new State();
    $state->setProtocolVersion(0);

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, new NullLogger());

    $daveStateProperty = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProperty->setAccessible(true);
    $daveStateProperty->setValue($ws, $state);

    $discordProperty = new \ReflectionProperty(WS::class, 'discord');
    $discordProperty->setAccessible(true);
    $discordProperty->setValue($ws, $discord);

    $socket = invokeWsMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send'])
        ->getMock();
    $socket->method('send')->willReturnCallback(function (mixed $payload) use ($sendHook): void {
        $sendHook(payloadFromStubHandlersSend($payload));
    });

    $socketProperty = new \ReflectionProperty(WS::class, 'socket');
    $socketProperty->setAccessible(true);
    $socketProperty->setValue($ws, $socket);

    return $ws;
}

function getWsDaveState(WS $ws): State
{
    $prop = new \ReflectionProperty(WS::class, 'daveState');
    $prop->setAccessible(true);

    return $prop->getValue($ws);
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeWsMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}

function payloadFromStubHandlersSend(mixed $payload): string
{
    return $payload instanceof \Ratchet\RFC6455\Messaging\Frame
        ? $payload->getPayload()
        : $payload;
}
