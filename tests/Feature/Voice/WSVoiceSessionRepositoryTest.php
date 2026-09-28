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
use Discord\Helpers\CacheConfig;
use Discord\Http\Http;
use Discord\Parts\Channel\Channel;
use Discord\Parts\WebSockets\VoiceSession;
use Discord\Parts\WebSockets\VoiceStateUpdate;
use Discord\Repository\AbstractRepository;
use Discord\Repository\VoiceSessionRepository;
use Discord\Voice\Client;
use Discord\Voice\Dave\Runtime;
use Discord\Voice\Dave\State;
use Discord\Voice\Gateway\WS;
use Discord\Voice\Manager;
use Discord\WebSockets\Op;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ratchet\Client\WebSocket;
use React\Cache\CacheInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

// From DiscordPHP 10.66 the bot's voice sessions are a repository of VoiceSession parts that DiscordPHP
// fills in from the gateway (discord-php/DiscordPHP#1441). The voice gateway identifies and resumes with
// the session DiscordPHP recorded. These tests skip on older DiscordPHP releases.

afterEach(function (): void {
    Runtime::reset();
});

it('identifies with the session id DiscordPHP recorded', function (): void {
    $sent = [];
    $discord = makeDiscordForVoiceSessionRepositoryTest();
    recordVoiceSessionForVoiceSessionRepositoryTest($discord);
    $ws = makeWsForVoiceSessionRepositoryTest($this, $discord, $sent);

    $ws->handleSendingOfLoginFrame();

    expect($sent)->toHaveCount(1);
    $payload = json_decode($sent[0], true, flags: JSON_THROW_ON_ERROR);
    expect($payload['op'])->toBe(Op::VOICE_IDENTIFY);
    expect($payload['d']['session_id'])->toBe('session-1');
});

it('resumes a reconnect with the session id DiscordPHP recorded', function (): void {
    $sent = [];
    $discord = makeDiscordForVoiceSessionRepositoryTest();
    recordVoiceSessionForVoiceSessionRepositoryTest($discord);
    $ws = makeWsForVoiceSessionRepositoryTest($this, $discord, $sent, reconnecting: true);

    $ws->handleSendingOfLoginFrame();

    expect($sent)->toHaveCount(1);
    $payload = json_decode($sent[0], true, flags: JSON_THROW_ON_ERROR);
    expect($payload['op'])->toBe(Op::VOICE_RESUME);
    expect($payload['d']['session_id'])->toBe('session-1');
});

it('stops resuming the session after a critical close', function (): void {
    $sent = [];
    $discord = makeDiscordForVoiceSessionRepositoryTest();
    recordVoiceSessionForVoiceSessionRepositoryTest($discord);
    $ws = makeWsForVoiceSessionRepositoryTest($this, $discord, $sent);

    $ws->handleClose(Op::CLOSE_INVALID_SESSION, 'session is no longer valid');

    $session = $discord->voice_sessions->get('guild_id', 'guild-1');
    expect($session)->toBeInstanceOf(VoiceSession::class);
    expect($session->session_id)->toBeNull();
    expect($session->isResumable())->toBeFalse();
});

it('resumes after a sweep, because the manager holds the session', function (): void {
    // DiscordPHP records the session before the manager hears the bot's voice state. A cache that sweeps
    // lets go of the parts nothing else holds, and a networked cache answers too late for the frame.
    $sent = [];
    $discord = makeDiscordForVoiceSessionRepositoryTest(new CacheConfig(makeLaterCacheForVoiceSessionRepositoryTest(), sweep: true));
    recordVoiceSessionForVoiceSessionRepositoryTest($discord);
    $manager = makeManagerForVoiceSessionRepositoryTest($this, $discord);

    $manager->stateUpdate($discord->getFactory()->part(VoiceStateUpdate::class, [
        'guild_id' => 'guild-1',
        'channel_id' => 'channel-1',
        'user_id' => 'bot-user',
        'session_id' => 'session-1',
        'deaf' => false,
        'mute' => false,
    ], true), makeChannelForVoiceSessionRepositoryTest());
    $discord->voice_sessions->cache->sweep();

    $ws = makeWsForVoiceSessionRepositoryTest($this, $discord, $sent, reconnecting: true);
    $ws->handleSendingOfLoginFrame();

    expect($sent)->toHaveCount(1);
    $payload = json_decode($sent[0], true, flags: JSON_THROW_ON_ERROR);
    expect($payload['op'])->toBe(Op::VOICE_RESUME);
    expect($payload['d']['session_id'])->toBe('session-1');
});

// Helpers

/**
 * A client whose voice sessions are DiscordPHP's repository, built without connecting. Skips the test when
 * the installed DiscordPHP is older than 10.66.
 */
function makeDiscordForVoiceSessionRepositoryTest(?CacheConfig $cacheConfig = null): Discord
{
    if (! class_exists(VoiceSessionRepository::class)) {
        TestCase::markTestSkipped('Needs DiscordPHP 10.66 or later, which keeps voice sessions in a repository.');
    }

    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();

    foreach ([
        'logger' => new NullLogger(),
        'http' => (new \ReflectionClass(Http::class))->newInstanceWithoutConstructor(),
        'factory' => new Factory($discord),
        'cacheConfig' => [AbstractRepository::class => $cacheConfig],
    ] as $property => $value) {
        (new \ReflectionProperty(Discord::class, $property))->setValue($discord, $value);
    }

    // DiscordPHP answers `$discord->id` and `$discord->voice_sessions` from its client user.
    $client = new \stdClass();
    $client->id = 'bot-user';
    $client->voice_sessions = new VoiceSessionRepository($discord);
    (new \ReflectionProperty(Discord::class, 'client'))->setValue($discord, $client);

    return $discord;
}

/**
 * Records the bot's session in guild-1 as DiscordPHP does, from its voice state and the voice server.
 */
function recordVoiceSessionForVoiceSessionRepositoryTest(Discord $discord): void
{
    $discord->voice_sessions->cache->set('guild-1', $discord->getFactory()->part(VoiceSession::class, [
        'guild_id' => 'guild-1',
        'channel_id' => 'channel-1',
        'user_id' => 'bot-user',
        'session_id' => 'session-1',
        'token' => 'voice-token',
        'endpoint' => 'voice.example.test:443',
    ], true));
}

function makeChannelForVoiceSessionRepositoryTest(): Channel
{
    $channel = (new \ReflectionClass(Channel::class))->newInstanceWithoutConstructor();
    (new \ReflectionProperty(Channel::class, 'attributes'))->setValue($channel, ['guild_id' => 'guild-1', 'id' => 'channel-1']);

    return $channel;
}

/**
 * @param array<int, string> $sent Receives every payload the gateway sends.
 */
function makeWsForVoiceSessionRepositoryTest(TestCase $test, Discord $discord, array &$sent, bool $reconnecting = false): WS
{
    Runtime::configureCallbacks(availabilityOverride: false);

    $voiceClient = invokeVoiceSessionRepositoryTestMethod($test, 'getMockBuilder', [Client::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['emit'])
        ->getMock();
    $voiceClient->method('emit')->willReturn(null);
    $voiceClient->channel = makeChannelForVoiceSessionRepositoryTest();
    $voiceClient->reconnecting = $reconnecting;

    $socket = invokeVoiceSessionRepositoryTestMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send', 'close'])
        ->getMock();
    $socket->method('send')->willReturnCallback(function (string $payload) use (&$sent): void {
        $sent[] = $payload;
    });

    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();

    foreach ([
        'daveState' => new State(),
        'discord' => $discord,
        'socket' => $socket,
        'data' => ['token' => 'voice-token', 'user_id' => 'bot-user'],
        'maxDaveProtocolVersion' => 1,
    ] as $property => $value) {
        (new \ReflectionProperty(WS::class, $property))->setValue($ws, $value);
    }

    $ws->vc = $voiceClient;

    return $ws;
}

/**
 * A manager with a voice client in guild-1.
 */
function makeManagerForVoiceSessionRepositoryTest(TestCase $test, Discord $discord): Manager
{
    $client = invokeVoiceSessionRepositoryTestMethod($test, 'getMockBuilder', [Client::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['setData'])
        ->getMock();
    $client->method('setData')->willReturnSelf();

    $manager = (new \ReflectionClass(Manager::class))->newInstanceWithoutConstructor();
    (new \ReflectionProperty(Manager::class, 'discord'))->setValue($manager, $discord);
    $manager->clients = ['guild-1' => $client];

    return $manager;
}

/**
 * A cache that keeps nothing and answers reads only later, as a networked cache does.
 */
function makeLaterCacheForVoiceSessionRepositoryTest(): CacheInterface
{
    return new class() implements CacheInterface {
        public function get($key, $default = null): PromiseInterface
        {
            return (new Deferred())->promise();
        }

        public function set($key, $value, $ttl = null): PromiseInterface
        {
            return resolve(true);
        }

        public function delete($key): PromiseInterface
        {
            return resolve(true);
        }

        public function getMultiple(array $keys, $default = null): PromiseInterface
        {
            return (new Deferred())->promise();
        }

        public function setMultiple(array $values, $ttl = null): PromiseInterface
        {
            return resolve(true);
        }

        public function deleteMultiple(array $keys): PromiseInterface
        {
            return resolve(true);
        }

        public function clear(): PromiseInterface
        {
            return resolve(true);
        }

        public function has($key): PromiseInterface
        {
            return (new Deferred())->promise();
        }
    };
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeVoiceSessionRepositoryTestMethod(object $object, string $method, array $arguments = []): mixed
{
    return (new \ReflectionMethod($object, $method))->invokeArgs($object, $arguments);
}
