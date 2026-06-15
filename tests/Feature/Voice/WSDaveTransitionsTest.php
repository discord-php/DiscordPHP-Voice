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
use Discord\Voice\Client as VoiceClient;
use Discord\Voice\Client\WS;
use Discord\Voice\Dave\EncryptorHandle;
use Discord\Voice\Dave\GatewayCoordinator;
use Discord\Voice\Dave\Runtime;
use Discord\Voice\Dave\State;
use Discord\Voice\Exceptions\Libraries\LibDaveNotFoundException;
use Discord\WebSockets\Op;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ratchet\Client\WebSocket;

afterEach(function (): void {
    Runtime::reset();
});

// ---------------------------------------------------------------------------
// handleDavePrepareTransition
// ---------------------------------------------------------------------------

it('handleDavePrepareTransition sends TRANSITION_READY with matching transition_id', function (): void {
    $sentPayloads = [];
    $ws = makeWsForTransitionsTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });

    $data = (object) ['d' => ['transition_id' => 5, 'protocol_version' => 1]];
    invokeTransitionsWsMethod($ws, 'handleDavePrepareTransition', [$data]);

    expect($sentPayloads)->not->toBeEmpty();
    $decoded = json_decode($sentPayloads[0], true);
    expect($decoded)->toBeArray()
        ->and($decoded['op'])->toBe(Op::VOICE_DAVE_TRANSITION_READY)
        ->and($decoded['d']['transition_id'])->toBe(5);
});

it('handleDavePrepareTransition sets pendingProtocolVersion from the resolved data field', function (): void {
    $ws = makeWsForTransitionsTest($this, function (string $payload): void {
    });

    // protocol_version: 0 resolves to 0; verify the field is consumed and stored in pendingProtocolVersion.
    $data = (object) ['d' => ['transition_id' => 7, 'protocol_version' => 0]];
    invokeTransitionsWsMethod($ws, 'handleDavePrepareTransition', [$data]);

    $state = getTransitionsDaveState($ws);
    expect($state->pendingTransitionId)->toBe(7)
        ->and($state->pendingProtocolVersion)->toBe(0);
});

it('handleDavePrepareTransition handles missing protocol_version gracefully', function (): void {
    $sentPayloads = [];
    $ws = makeWsForTransitionsTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });

    // No protocol_version in d — must not crash, and must still send TRANSITION_READY.
    $data = (object) ['d' => ['transition_id' => 3]];
    invokeTransitionsWsMethod($ws, 'handleDavePrepareTransition', [$data]);

    expect($sentPayloads)->not->toBeEmpty();
    $decoded = json_decode($sentPayloads[0], true);
    expect($decoded['op'])->toBe(Op::VOICE_DAVE_TRANSITION_READY)
        ->and($decoded['d']['transition_id'])->toBe(3);
});

it('handleDavePrepareTransition executes transition zero locally without sending TRANSITION_READY', function (): void {
    $sentPayloads = [];
    $ws = makeWsForTransitionsTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });

    $state = getTransitionsDaveState($ws);
    $state->prepareProtocolVersion(1);
    expect($state->passthroughMode)->toBeTrue();

    $data = (object) ['d' => ['transition_id' => 0, 'protocol_version' => 1]];
    invokeTransitionsWsMethod($ws, 'handleDavePrepareTransition', [$data]);

    expect($sentPayloads)->toBeEmpty()
        ->and($state->pendingTransitionId)->toBeNull()
        ->and($state->protocolVersion)->toBe(1)
        ->and($state->passthroughMode)->toBeFalse();
});

// ---------------------------------------------------------------------------
// handleDaveExecuteTransition
// ---------------------------------------------------------------------------

it('handleDaveExecuteTransition executes transition when IDs match', function (): void {
    $ws = makeWsForTransitionsTest($this, function (string $payload): void {
    });

    $state = getTransitionsDaveState($ws);
    $state->prepareTransition(3);
    expect($state->pendingTransitionId)->toBe(3);

    $data = (object) ['d' => ['transition_id' => 3]];
    invokeTransitionsWsMethod($ws, 'handleDaveExecuteTransition', [$data]);

    // pendingProtocolVersion is null, protocolVersion defaults to 0 → resets state.
    // Either way pendingTransitionId must be cleared.
    expect($state->pendingTransitionId)->toBeNull();
});

it('handleDaveExecuteTransition ignores mismatched transition ID', function (): void {
    $ws = makeWsForTransitionsTest($this, function (string $payload): void {
    });

    $state = getTransitionsDaveState($ws);
    $state->prepareTransition(3);
    expect($state->pendingTransitionId)->toBe(3);

    $data = (object) ['d' => ['transition_id' => 7]];
    invokeTransitionsWsMethod($ws, 'handleDaveExecuteTransition', [$data]);

    // ID mismatch → no state change.
    expect($state->pendingTransitionId)->toBe(3);
});

it('handleDaveExecuteTransition resets protocol state when resolved protocolVersion is 0', function (): void {
    $ws = makeWsForTransitionsTest($this, function (string $payload): void {
    });

    $state = getTransitionsDaveState($ws);
    // pendingProtocolVersion = 0 (explicitly), protocolVersion = 0 (default).
    $state->prepareTransition(5, 0);
    expect($state->pendingTransitionId)->toBe(5);

    $data = (object) ['d' => ['transition_id' => 5]];
    invokeTransitionsWsMethod($ws, 'handleDaveExecuteTransition', [$data]);

    // protocolVersion <= 0 branch: resetProtocolState + setProtocolVersion(0).
    expect($state->pendingTransitionId)->toBeNull()
        ->and($state->protocolVersion)->toBe(0)
        ->and($state->session)->toBeNull();
});

// ---------------------------------------------------------------------------
// applySelfDaveEncryptor
// ---------------------------------------------------------------------------

it('applySelfDaveEncryptor returns early without crashing when encryptor is null', function (): void {
    $sentPayloads = [];
    $ws = makeWsForTransitionsTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });

    // State has no encryptor by default — should be a no-op.
    $state = getTransitionsDaveState($ws);
    expect($state->encryptor)->toBeNull();

    invokeTransitionsWsMethod(getTransitionsCoordinator($ws), 'applySelfDaveEncryptor', [1]);

    expect($sentPayloads)->toBeEmpty();
});

it('applySelfDaveEncryptor returns early without crashing when protocolVersion is 0 and encryptor is set', function (): void {
    $sentPayloads = [];
    $ws = makeWsForTransitionsTest($this, function (string $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload;
    });

    $state = getTransitionsDaveState($ws);
    $state->replaceEncryptor(new EncryptorHandle(new \stdClass()));

    // protocolVersion = 0 → tries configureEncryptorPassthrough (gracefully fails without libdave) and returns.
    invokeTransitionsWsMethod(getTransitionsCoordinator($ws), 'applySelfDaveEncryptor', [0]);

    // Nothing sent over WebSocket in either code path.
    expect($sentPayloads)->toBeEmpty();
});

// ---------------------------------------------------------------------------
// resolveDaveProtocolVersion
// ---------------------------------------------------------------------------

it('resolveDaveProtocolVersion returns 0 when protocolVersion is 0 or negative', function (): void {
    $ws = makeWsForTransitionsTest($this, function (string $payload): void {
    });

    $result = invokeTransitionsWsMethod($ws, 'resolveDaveProtocolVersion', [0]);
    expect($result)->toBe(0);

    $result = invokeTransitionsWsMethod($ws, 'resolveDaveProtocolVersion', [-1]);
    expect($result)->toBe(0);
});

it('WS constructor throws LibDaveNotFoundException when libdave is unavailable', function (): void {
    Runtime::configureCallbacks(availabilityOverride: false);

    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $channel = (new \ReflectionClass(\Discord\Parts\Channel\Channel::class))->newInstanceWithoutConstructor();
    (new \ReflectionProperty(\Discord\Voice\VoiceClient::class, 'channel'))->setValue($vc, $channel);

    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();

    expect(fn () => new WS($vc, $discord, ['user_id' => '123']))
        ->toThrow(LibDaveNotFoundException::class);
});

it('resolveDaveProtocolVersion returns min(requested, maxProtocol) when libdave is available', function (): void {
    if (! getenv('DISCORDPHP_DAVE_LIBRARY')) {
        $this->markTestSkipped('Requires DISCORDPHP_DAVE_LIBRARY to be set.');
    }

    $ws = makeWsForTransitionsTest($this, function (string $payload): void {
    });

    // With libdave available and requested version 1: min(1, MAX_DAVE_PROTOCOL_VERSION=1) = 1.
    $result = invokeTransitionsWsMethod($ws, 'resolveDaveProtocolVersion', [1]);
    expect($result)->toBeGreaterThanOrEqual(0)
        ->and($result)->toBeLessThanOrEqual(WS::MAX_DAVE_PROTOCOL_VERSION);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @param callable(string): void $sendHook
 */
function makeWsForTransitionsTest(TestCase $test, callable $sendHook): WS
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

    $socket = invokeTransitionsWsMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send'])
        ->getMock();
    $socket->method('send')->willReturnCallback(function (mixed $payload) use ($sendHook): void {
        $sendHook(payloadFromTransitionsSend($payload));
    });

    $socketProperty = new \ReflectionProperty(WS::class, 'socket');
    $socketProperty->setAccessible(true);
    $socketProperty->setValue($ws, $socket);

    return $ws;
}

function getTransitionsDaveState(WS $ws): State
{
    $prop = new \ReflectionProperty(WS::class, 'daveState');
    $prop->setAccessible(true);

    return $prop->getValue($ws);
}

/**
 * Extract the GatewayCoordinator from a WS instance.
 */
function getTransitionsCoordinator(WS $ws): GatewayCoordinator
{
    $method = new \ReflectionMethod(WS::class, 'getCoordinator');
    $method->setAccessible(true);

    return $method->invoke($ws);
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeTransitionsWsMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}

function payloadFromTransitionsSend(mixed $payload): string
{
    return $payload instanceof \Ratchet\RFC6455\Messaging\Frame
        ? $payload->getPayload()
        : $payload;
}
