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
use Discord\Voice\Dave\EncryptorHandle;
use Discord\Voice\Dave\Runtime;
use Discord\Voice\Dave\SessionHandle;
use Discord\Voice\Dave\State;
use Discord\WebSockets\Op;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ratchet\Client\WebSocket;

afterEach(function (): void {
    Runtime::reset();
});

// ──────────────────────────────────────────────────────────────
// keyPackageSent flag — duplicate-send prevention
// ──────────────────────────────────────────────────────────────

it('sendDaveKeyPackage is a no-op when keyPackageSent is already true', function (): void {
    $sentPayloads = [];
    $ws = makeWsForCharacterizationTest($this, $sentPayloads);

    $state = getCharacterizationDaveState($ws);
    $state->replaceSession(new SessionHandle(new \stdClass()));
    $state->replaceEncryptor(new EncryptorHandle(new \stdClass()));
    $state->keyPackageSent = true; // guard already raised

    Runtime::configureCallbacks(
        keyPackageCallback: fn (SessionHandle $session): ?string => 'key-package'
    );

    invokeCharacterizationMethod($ws, 'sendDaveKeyPackage');

    // keyPackageSent=true → early return, nothing sent over the wire.
    expect($sentPayloads)->toBeEmpty();
});

it('sendDaveKeyPackage sends Op 26 exactly once and raises the keyPackageSent flag', function (): void {
    $sentPayloads = [];
    $ws = makeWsForCharacterizationTest($this, $sentPayloads);

    $state = getCharacterizationDaveState($ws);
    $state->replaceSession(new SessionHandle(new \stdClass()));
    $state->replaceEncryptor(new EncryptorHandle(new \stdClass()));

    Runtime::configureCallbacks(
        keyPackageCallback: fn (SessionHandle $session): ?string => 'kp-bytes'
    );

    // First call — should send Op 26 and set the flag.
    invokeCharacterizationMethod($ws, 'sendDaveKeyPackage');

    expect($sentPayloads)->toHaveCount(1);
    $firstFrame = BinaryFrame::fromClientPayload($sentPayloads[0]);
    expect($firstFrame?->opcode)->toBe(Op::VOICE_DAVE_MLS_KEY_PACKAGE)
        ->and($firstFrame?->payload)->toBe('kp-bytes')
        ->and($state->keyPackageSent)->toBeTrue();

    // Second call — flag is now raised; nothing additional is sent.
    invokeCharacterizationMethod($ws, 'sendDaveKeyPackage');

    expect($sentPayloads)->toHaveCount(1);
});

// ──────────────────────────────────────────────────────────────
// handleDaveMlsProposals — failure count resets on success
// ──────────────────────────────────────────────────────────────

it('handleDaveMlsProposals resets proposalFailureCount to 0 after a successful commit', function (): void {
    Runtime::configureCallbacks(
        null,
        null,
        fn (string $payload, int $protocolVersion): ?string => "commit:{$payload}"
    );

    $sentPayloads = [];
    $ws = makeWsForCharacterizationTest($this, $sentPayloads);

    $state = getCharacterizationDaveState($ws);
    $state->proposalFailureCount = 2; // simulate two prior failures

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_PROPOSALS, 'proposals-payload');
    invokeCharacterizationMethod($ws, 'handleDaveMlsProposals', [$frame]);

    // Success path: commit was built → Op 28 sent and failure counter reset.
    expect($state->proposalFailureCount)->toBe(0);

    $sentFrame = BinaryFrame::fromClientPayload($sentPayloads[0]);
    expect($sentFrame?->opcode)->toBe(Op::VOICE_DAVE_MLS_COMMIT_WELCOME);
});

it('handleDaveMlsProposals does not reset proposalFailureCount when commit build fails', function (): void {
    Runtime::configureCallbacks(
        null,
        null,
        fn (string $payload, int $protocolVersion): ?string => null
    );

    $sentPayloads = [];
    $ws = makeWsForCharacterizationTest($this, $sentPayloads);

    $state = getCharacterizationDaveState($ws);
    $state->proposalFailureCount = 1;

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_PROPOSALS, 'proposals-payload');
    invokeCharacterizationMethod($ws, 'handleDaveMlsProposals', [$frame]);

    // Failure path: count is incremented, NOT reset to 0.
    expect($state->proposalFailureCount)->toBe(2)
        ->and($sentPayloads)->toBeEmpty();
});

// ──────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────

/**
 * @param array<int, string> $sentPayloads Reference array that collects raw send() payloads.
 */
function makeWsForCharacterizationTest(TestCase $test, array &$sentPayloads): WS
{
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();

    $state = new State();
    $state->setProtocolVersion(1);
    $state->setIdentity('42', 123);

    $loggerProp = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProp->setAccessible(true);
    $loggerProp->setValue($discord, new NullLogger());

    $daveStateProp = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProp->setAccessible(true);
    $daveStateProp->setValue($ws, $state);

    $discordProp = new \ReflectionProperty(WS::class, 'discord');
    $discordProp->setAccessible(true);
    $discordProp->setValue($ws, $discord);

    $socket = invokeCharacterizationMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send', 'close'])
        ->getMock();
    $socket->method('send')->willReturnCallback(function (mixed $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload instanceof \Ratchet\RFC6455\Messaging\Frame
            ? $payload->getPayload()
            : $payload;
    });

    $socketProp = new \ReflectionProperty(WS::class, 'socket');
    $socketProp->setAccessible(true);
    $socketProp->setValue($ws, $socket);

    return $ws;
}

function getCharacterizationDaveState(WS $ws): State
{
    $prop = new \ReflectionProperty(WS::class, 'daveState');
    $prop->setAccessible(true);

    return $prop->getValue($ws);
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeCharacterizationMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}
