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
use Discord\Parts\Part;
use Discord\Parts\Permissions\RolePermission;
use Discord\Parts\WebSockets\VoiceServerUpdate;
use Discord\Voice\Client;
use Discord\Voice\Dave\Runtime;
use Discord\Voice\Manager;
use Discord\Voice\VoiceClient;
use Discord\WebSockets\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

afterEach(function (): void {
    Runtime::reset();
});

// ---------------------------------------------------------------------------
// Test 1 – VoiceClient must NOT disconnect itself when it emits 'ready'
//
// VoiceClient::boot()'s 'ready' handler checks whether the manager already
// holds a *different* client for the guild (`!== $this`) and, if so, disconnects
// that old client.  Because Manager::joinChannel() inserts the new client into
// $manager->clients before any events arrive, the new client IS the stored
// client when 'ready' fires.  The `!== $this` guard must therefore prevent any
// disconnect() call on the join-created client itself.
// ---------------------------------------------------------------------------
it('join-created VoiceClient does not disconnect itself when it emits ready', function (): void {
    Runtime::configureCallbacks(availabilityOverride: true);

    $sent = [];
    $discord = makeDiscordForLifecycleTest($this, $sent);
    $channel = makeChannelForLifecycleTest($this, 'guild-lc');
    $manager = new Manager($discord);
    $deferred = new Deferred();

    // Partial mock: stub start() so no real WS is opened, and spy on disconnect().
    $vc = $this->getMockBuilder(VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['start', 'disconnect'])
        ->getMock();

    $vc->method('start')->willReturn(true);

    $disconnectCalled = false;
    $vc->method('disconnect')->willReturnCallback(function () use (&$disconnectCalled): void {
        $disconnectCalled = true;
    });

    injectVoiceClientPropsForLifecycleTest($vc, $discord, $channel, $manager, $deferred);

    // Replicate what Manager::joinChannel() does: put the client in the map
    // *before* the client is ready (this is what triggers the bug).
    $manager->clients['guild-lc'] = $vc;

    // boot() registers the once('ready') handler that contains the buggy check.
    $vc->boot();

    // Simulate the WS finishing its handshake.
    $vc->emit('ready', [$vc]);

    expect($disconnectCalled)->toBeFalse('VoiceClient must NOT call disconnect() when it emits ready via a Manager-created join');
});

// ---------------------------------------------------------------------------
// Test 2 – Manager::joinChannel() resolves its promise when client emits 'ready'
// ---------------------------------------------------------------------------
it('Manager joinChannel resolves its promise when the client emits ready', function (): void {
    Runtime::configureCallbacks(availabilityOverride: true);

    $sent = [];
    $discord = makeDiscordForLifecycleTest($this, $sent);
    $channel = makeChannelForLifecycleTest($this, 'guild-lc2');
    $manager = new Manager($discord);

    // serverUpdate()'s 'ready' handler sets $discord->voice->clients[…], so
    // point discord->voice at our manager.
    $discord->voice = $manager;

    $voiceSessions = [];
    $promise = $manager->joinChannel($channel, $discord, $voiceSessions);
    $client   = $manager->clients['guild-lc2'];

    // Capture whether/what the promise resolves to before emitting anything.
    $resolved = null;
    $rejected = null;
    $promise->then(
        function ($value) use (&$resolved): void { $resolved = $value; },
        function ($reason) use (&$rejected): void { $rejected = $reason; },
    );

    // Trigger the VOICE_SERVER_UPDATE gateway event so that Manager::serverUpdate()
    // registers its once('ready') listener on the client.
    $serverUpdatePart = makeVoiceServerUpdateForLifecycleTest('guild-lc2', 'tok', 'ep.discord.gg');
    $discord->emit(Event::VOICE_SERVER_UPDATE, [$serverUpdatePart, $discord]);

    // Now the client has the 'ready' listener.  Fire it.
    $client->emit('ready', [$client]);

    expect($rejected)->toBeNull('promise must not reject on ready');
    expect($resolved)->toBe($client, 'promise must resolve with the VoiceClient');
});

// ---------------------------------------------------------------------------
// Test 3a – Manager::joinChannel() rejects its promise when client emits 'error'
// ---------------------------------------------------------------------------
it('Manager joinChannel rejects its promise when the client emits error before ready', function (): void {
    Runtime::configureCallbacks(availabilityOverride: true);

    $sent = [];
    $discord = makeDiscordForLifecycleTest($this, $sent);
    $channel = makeChannelForLifecycleTest($this, 'guild-lc3a');
    $manager = new Manager($discord);
    $discord->voice = $manager;

    $voiceSessions = [];
    $promise = $manager->joinChannel($channel, $discord, $voiceSessions);
    $client   = $manager->clients['guild-lc3a'];

    $resolved = null;
    $rejected = null;
    $promise->then(
        function ($value) use (&$resolved): void { $resolved = $value; },
        function ($reason) use (&$rejected): void { $rejected = $reason; },
    );

    $serverUpdatePart = makeVoiceServerUpdateForLifecycleTest('guild-lc3a', 'tok', 'ep.discord.gg');
    $discord->emit(Event::VOICE_SERVER_UPDATE, [$serverUpdatePart, $discord]);

    $error = new \RuntimeException('voice connection failed');
    $client->emit('error', [$error]);

    expect($resolved)->toBeNull('promise must not resolve on error');
    expect($rejected)->toBe($error, 'promise must reject with the emitted error');
});

// ---------------------------------------------------------------------------
// Test 3b – Manager::joinChannel() rejects its promise when client emits 'close'
//
// BUG: Manager::serverUpdate()'s 'close' handler (lines 261-266 of Manager.php)
// does NOT call $deferred->reject().  It only cleans up bookkeeping.
// When a voice client closes before a WS session is established (i.e. before
// setData() triggers boot()), boot()'s own 'close' handler — which does reject
// — is never registered.  Manager's is the only 'close' handler in play, and
// it leaves the join promise pending forever.
// ---------------------------------------------------------------------------
it('Manager joinChannel rejects its promise when the client emits close before ready', function (): void {
    Runtime::configureCallbacks(availabilityOverride: true);

    $sent = [];
    $discord = makeDiscordForLifecycleTest($this, $sent);
    $channel = makeChannelForLifecycleTest($this, 'guild-lc3b');
    $manager = new Manager($discord);
    $discord->voice = $manager;

    $voiceSessions = [];
    $promise = $manager->joinChannel($channel, $discord, $voiceSessions);
    $client   = $manager->clients['guild-lc3b'];

    $resolved = null;
    $rejected = null;
    $promise->then(
        function ($value) use (&$resolved): void { $resolved = $value; },
        function ($reason) use (&$rejected): void { $rejected = $reason; },
    );

    $serverUpdatePart = makeVoiceServerUpdateForLifecycleTest('guild-lc3b', 'tok', 'ep.discord.gg');
    $discord->emit(Event::VOICE_SERVER_UPDATE, [$serverUpdatePart, $discord]);

    $client->emit('close');

    // The promise SHOULD reject when the client closes before becoming ready.
    expect($resolved)->toBeNull('promise must not resolve on early close');
    expect($rejected)->not->toBeNull('promise must reject when client closes before ready');
});

// ---------------------------------------------------------------------------
// Test 4 – Manager cleans up its gateway (VOICE_SERVER_UPDATE) listener after
//          the client emits 'ready', so the listener fires at most once.
// ---------------------------------------------------------------------------
it('Manager removes the server gateway listener after ready fires', function (): void {
    Runtime::configureCallbacks(availabilityOverride: true);

    $sent = [];
    $discord = makeDiscordForLifecycleTest($this, $sent);
    $channel = makeChannelForLifecycleTest($this, 'guild-lc4');
    $manager = new Manager($discord);
    $discord->voice = $manager;

    $voiceSessions = [];
    $manager->joinChannel($channel, $discord, $voiceSessions);
    $client = $manager->clients['guild-lc4'];

    // Read private $serverListeners via reflection.
    $serverListenersProp = new \ReflectionProperty(Manager::class, 'serverListeners');
    $serverListenersProp->setAccessible(true);

    // Before serverUpdate is called the listener is registered.
    expect($serverListenersProp->getValue($manager))->toHaveKey('guild-lc4');

    $serverUpdatePart = makeVoiceServerUpdateForLifecycleTest('guild-lc4', 'tok', 'ep.discord.gg');
    $discord->emit(Event::VOICE_SERVER_UPDATE, [$serverUpdatePart, $discord]);

    // Fire ready – this triggers removeServerListener() inside Manager.
    $client->emit('ready', [$client]);

    // The server listener MUST have been removed from the internal map.
    expect($serverListenersProp->getValue($manager))->not->toHaveKey('guild-lc4');

    // Emitting ready a second time must NOT call the listener again (once() semantics).
    $resolveCount = 0;
    $client->on('ready', function () use (&$resolveCount): void {
        $resolveCount++;
    });
    $client->emit('ready', [$client]);
    expect($resolveCount)->toBe(1, 'second ready emit should NOT trigger the original once() listener again');
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Builds a Discord mock suitable for lifecycle tests.
 * – onlyMethods(['send']) so EventEmitter (on/emit/removeListener) works normally.
 * – logger property set to NullLogger so all getLogger()/->logger accesses succeed.
 * – options protected property set to include dnsConfig.
 *
 * @param array<int, mixed> $sent Captured send() payloads, populated by reference.
 */
function makeDiscordForLifecycleTest(TestCase $test, array &$sent): Discord
{
    $discord = invokeLifecycleTestMethod($test, 'getMockBuilder', [Discord::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send'])
        ->getMock();

    $discord->method('send')->willReturnCallback(function ($data) use (&$sent): void {
        $sent[] = $data;
    });

    $loggerProp = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProp->setAccessible(true);
    $loggerProp->setValue($discord, new NullLogger());

    $optionsProp = new \ReflectionProperty(Discord::class, 'options');
    $optionsProp->setAccessible(true);
    $optionsProp->setValue($discord, ['dnsConfig' => '8.8.8.8']);

    // voice_sessions and voice are public; they have default values from the
    // class declaration so they are already usable on the mock object.

    return $discord;
}

/**
 * Builds a Channel mock with a specific guild ID suitable for lifecycle tests.
 */
function makeChannelForLifecycleTest(TestCase $test, string $guildId): Channel
{
    $channel = invokeLifecycleTestMethod($test, 'getMockBuilder', [Channel::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['isVoiceBased', 'getBotPermissions'])
        ->getMock();

    $channel->method('isVoiceBased')->willReturn(true);
    $channel->method('getBotPermissions')->willReturn(makeRolePermissionForLifecycleTest(connect: true, speak: true));

    $attrProp = new \ReflectionProperty(Part::class, 'attributes');
    $attrProp->setAccessible(true);
    $attrProp->setValue($channel, [
        'guild_id' => $guildId,
        'id'       => 'chan-' . $guildId,
        'bitrate'  => 64000,
    ]);

    return $channel;
}

function makeRolePermissionForLifecycleTest(bool $connect, bool $speak): RolePermission
{
    $perm = (new \ReflectionClass(RolePermission::class))->newInstanceWithoutConstructor();

    $attrProp = new \ReflectionProperty(Part::class, 'attributes');
    $attrProp->setAccessible(true);
    $attrProp->setValue($perm, ['connect' => $connect, 'speak' => $speak]);

    return $perm;
}

/**
 * Builds a VoiceServerUpdate Part mock with the given guild/token/endpoint.
 */
function makeVoiceServerUpdateForLifecycleTest(string $guildId, string $token, string $endpoint): VoiceServerUpdate
{
    $part = (new \ReflectionClass(VoiceServerUpdate::class))->newInstanceWithoutConstructor();

    $attrProp = new \ReflectionProperty(Part::class, 'attributes');
    $attrProp->setAccessible(true);
    $attrProp->setValue($part, [
        'guild_id' => $guildId,
        'token'    => $token,
        'endpoint' => $endpoint,
    ]);

    return $part;
}

/**
 * Injects the minimal set of properties a VoiceClient needs for boot() + ready-handler tests.
 */
function injectVoiceClientPropsForLifecycleTest(
    VoiceClient $vc,
    Discord $discord,
    Channel $channel,
    Manager $manager,
    Deferred $deferred,
): void {
    $vc->discord = $discord;
    $vc->channel = $channel;
    $vc->manager = $manager;
    $vc->data    = ['deaf' => false, 'mute' => false];

    $deferredProp = new \ReflectionProperty(VoiceClient::class, 'deferred');
    $deferredProp->setAccessible(true);
    $deferredProp->setValue($vc, $deferred);
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeLifecycleTestMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}
