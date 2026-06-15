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
use Discord\Parts\Voice\UserConnected;
use Discord\Voice\Client;
use Discord\Voice\Client\WS;
use Discord\Voice\Dave\DecryptorHandle;
use Discord\Voice\Dave\GatewayCoordinator;
use Discord\Voice\Dave\KeyRatchetHandle;
use Discord\Voice\Dave\Runtime;
use Discord\Voice\Dave\SessionHandle;
use Discord\Voice\Dave\State;
use Discord\WebSockets\Op;
use Discord\WebSockets\Payload;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

afterEach(function (): void {
    Runtime::reset();
});

// ---------------------------------------------------------------------------
// handleClientConnect
// ---------------------------------------------------------------------------

it('handleClientConnect adds users to daveState recognizedUsers', function (): void {
    $ws = makeWsForRemoteUsersTest($this);

    invokeRemoteUsersMethod($ws, 'handleClientConnect', [
        new Payload(Op::VOICE_CLIENT_CONNECT, ['user_ids' => ['user1', 'user2']]),
    ]);

    $state = getRemoteUsersDaveState($ws);
    expect($state->recognizedUsers())->toContain('user1')->toContain('user2');
});

it('handleClientConnect deduplicates users on repeated connect', function (): void {
    $ws = makeWsForRemoteUsersTest($this);

    invokeRemoteUsersMethod($ws, 'handleClientConnect', [
        new Payload(Op::VOICE_CLIENT_CONNECT, ['user_ids' => ['user1', 'user2']]),
    ]);
    invokeRemoteUsersMethod($ws, 'handleClientConnect', [
        new Payload(Op::VOICE_CLIENT_CONNECT, ['user_ids' => ['user2', 'user3']]),
    ]);

    $state = getRemoteUsersDaveState($ws);
    $users = $state->recognizedUsers();
    expect(array_count_values($users)['user2'])->toBe(1);
    expect($users)->toContain('user1')->toContain('user2')->toContain('user3');
});

it('handleClientConnect ignores non-array user_ids', function (): void {
    $ws = makeWsForRemoteUsersTest($this);

    invokeRemoteUsersMethod($ws, 'handleClientConnect', [
        new Payload(Op::VOICE_CLIENT_CONNECT, ['user_ids' => null]),
    ]);

    expect(getRemoteUsersDaveState($ws)->recognizedUsers())->toBe([]);
});

it('handleClientConnect ignores missing user_ids key', function (): void {
    $ws = makeWsForRemoteUsersTest($this);

    invokeRemoteUsersMethod($ws, 'handleClientConnect', [
        new Payload(Op::VOICE_CLIENT_CONNECT, []),
    ]);

    expect(getRemoteUsersDaveState($ws)->recognizedUsers())->toBe([]);
});

// ---------------------------------------------------------------------------
// handleClientDisconnect
// ---------------------------------------------------------------------------

it('handleClientDisconnect removes user from daveState', function (): void {
    $ws = makeWsForRemoteUsersTest($this);
    $state = getRemoteUsersDaveState($ws);
    $state->addRecognizedUsers(['123']);

    invokeRemoteUsersMethod($ws, 'handleClientDisconnect', [
        new Payload(Op::VOICE_CLIENT_DISCONNECT, ['user_id' => '123']),
    ]);

    expect($state->recognizedUsers())->not->toContain('123');
});

it('handleClientDisconnect no crash when user not in state', function (): void {
    $ws = makeWsForRemoteUsersTest($this);

    // Should not throw — just a no-op when the user was never added.
    invokeRemoteUsersMethod($ws, 'handleClientDisconnect', [
        new Payload(Op::VOICE_CLIENT_DISCONNECT, ['user_id' => 'unknown']),
    ]);

    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// recognizedUsersIncludingSelf (on State — public method, tested directly)
// ---------------------------------------------------------------------------

it('recognizedUsersIncludingSelf includes own userId', function (): void {
    $state = new State();
    $state->setIdentity('self-id', null);
    $state->addRecognizedUsers(['other-user']);

    expect($state->recognizedUsersIncludingSelf())
        ->toContain('self-id')
        ->toContain('other-user');
});

it('recognizedUsersIncludingSelf returns just self when no other users', function (): void {
    $state = new State();
    $state->setIdentity('self-id', null);

    expect($state->recognizedUsersIncludingSelf())->toBe(['self-id']);
});

// ---------------------------------------------------------------------------
// prepareRemoteDaveDecryptor (private — invoked via reflection)
// ---------------------------------------------------------------------------

it('prepareRemoteDaveDecryptor returns early when protocolVersion is zero', function (): void {
    $ws = makeWsForRemoteUsersTest($this);
    $state = getRemoteUsersDaveState($ws);

    invokeRemoteUsersMethod(getRemoteUsersCoordinator($ws), 'prepareRemoteDaveDecryptor', ['user1', 0]);

    expect($state->getDecryptor('user1'))->toBeNull();
});

it('prepareRemoteDaveDecryptor handles createDecryptor failure gracefully without libdave', function (): void {
    $ws = makeWsForRemoteUsersTest($this);
    $state = getRemoteUsersDaveState($ws);

    // Inject a non-null session to pass the session===null guard.
    $session = (new \ReflectionClass(SessionHandle::class))->newInstanceWithoutConstructor();
    // Initialize the handle property so destroy() doesn't fail on teardown.
    $handleProp = new \ReflectionProperty(\Discord\Voice\Dave\NativeHandle::class, 'handle');
    $handleProp->setAccessible(true);
    $handleProp->setValue($session, null);
    $sessionProp = new \ReflectionProperty(State::class, 'session');
    $sessionProp->setAccessible(true);
    $sessionProp->setValue($state, $session);

    // With no libdave, DaveRuntime::createDecryptor() returns null.
    // The method should log a warning and return without storing any decryptor.
    invokeRemoteUsersMethod(getRemoteUsersCoordinator($ws), 'prepareRemoteDaveDecryptor', ['user1', 1]);

    expect($state->getDecryptor('user1'))->toBeNull();
});

it('prepareRemoteDaveDecryptor keeps transition passthrough while installing a new key ratchet', function (): void {
    $ws = makeWsForRemoteUsersTest($this);
    $state = getRemoteUsersDaveState($ws);
    $state->replaceSession(new SessionHandle(new \stdClass()));

    $decryptor = new DecryptorHandle(new \stdClass());
    $keyRatchet = new KeyRatchetHandle(new \stdClass());
    $calls = [];

    Runtime::configureCallbacks(
        createDecryptorCallback: fn (): ?DecryptorHandle => $decryptor,
        keyRatchetCallback: fn (SessionHandle $session, string $userId): ?KeyRatchetHandle => $keyRatchet,
        decryptorPassthroughCallback: function (DecryptorHandle $configuredDecryptor, bool $passthroughMode) use (&$calls, $decryptor): bool {
            expect($configuredDecryptor)->toBe($decryptor);
            $calls[] = ['passthrough', $passthroughMode];

            return true;
        },
        decryptorKeyRatchetCallback: function (DecryptorHandle $configuredDecryptor, KeyRatchetHandle $configuredKeyRatchet) use (&$calls, $decryptor, $keyRatchet): bool {
            expect($configuredDecryptor)->toBe($decryptor)
                ->and($configuredKeyRatchet)->toBe($keyRatchet);
            $calls[] = ['key_ratchet'];

            return true;
        }
    );

    invokeRemoteUsersMethod(getRemoteUsersCoordinator($ws), 'prepareRemoteDaveDecryptor', ['user1', 1]);

    expect($calls)->toBe([
        ['passthrough', true],
        ['key_ratchet'],
        ['passthrough', false],
    ])->and($state->getDecryptor('user1'))->toBe($decryptor)
        ->and($state->getKeyRatchet('user1'))->toBe($keyRatchet);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build a WS instance with all dependencies wired up for remote-user tests.
 * Mocks Discord (with logger/factory), Factory, UserConnected, and Client.
 */
function makeWsForRemoteUsersTest(TestCase $test): WS
{
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();

    $discord = invokeRemoteUsersMethod($test, 'getMockBuilder', [Discord::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['getLogger', 'getFactory'])
        ->getMock();

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, new NullLogger());

    $discord->method('getLogger')->willReturn(new NullLogger());

    $partMock = invokeRemoteUsersMethod($test, 'getMockBuilder', [UserConnected::class])
        ->disableOriginalConstructor()
        ->getMock();

    $factoryMock = invokeRemoteUsersMethod($test, 'getMockBuilder', [Factory::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['part'])
        ->getMock();
    $factoryMock->method('part')->willReturn($partMock);

    $discord->method('getFactory')->willReturn($factoryMock);

    $vcMock = invokeRemoteUsersMethod($test, 'getMockBuilder', [Client::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['emit'])
        ->getMock();
    $vcMock->clientsConnected = [];

    $state = new State();

    $daveStateProp = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProp->setAccessible(true);
    $daveStateProp->setValue($ws, $state);

    $discordProp = new \ReflectionProperty(WS::class, 'discord');
    $discordProp->setAccessible(true);
    $discordProp->setValue($ws, $discord);

    $ws->vc = $vcMock;

    return $ws;
}

/**
 * Extract the GatewayCoordinator from a WS instance.
 */
function getRemoteUsersCoordinator(WS $ws): GatewayCoordinator
{
    $prop = new \ReflectionProperty(WS::class, 'coordinator');
    $prop->setAccessible(true);

    $coordinator = $prop->getValue($ws);
    if (! $coordinator instanceof GatewayCoordinator) {
        // Force lazy init by accessing getCoordinator via reflection
        $method = new \ReflectionMethod(WS::class, 'getCoordinator');
        $method->setAccessible(true);
        $coordinator = $method->invoke($ws);
    }

    return $coordinator;
}

/**
 * Extract the daveState from a WS instance.
 */
function getRemoteUsersDaveState(WS $ws): State
{
    $prop = new \ReflectionProperty(WS::class, 'daveState');
    $prop->setAccessible(true);

    return $prop->getValue($ws);
}

/**
 * Invoke a protected or private method on any object (or TestCase for mock builders).
 *
 * @param array<int, mixed> $arguments
 */
function invokeRemoteUsersMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}
