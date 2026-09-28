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
use Discord\Factory\Factory;
use Discord\Helpers\CacheConfig;
use Discord\Http\Http;
use Discord\Parts\Part;
use Discord\Parts\WebSockets\VoiceSession;
use Discord\Repository\AbstractRepository;
use Discord\Repository\VoiceSessionRepository;
use Discord\Voice\VoiceSessions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\Cache\CacheInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

// DiscordPHP keeps the bot's voice session in each guild: before 10.66 in an array of guild IDs to session
// IDs that this package writes, and from 10.66 as VoiceSession parts in a repository stored through the
// cache, which DiscordPHP fills in from the gateway (discord-php/DiscordPHP#1441).

// ── An array, before DiscordPHP 10.66 ──────────────────────────────────────

it('reads a session id from the array', function (): void {
    $discord = makeDiscordWithArrayForVoiceSessionsTest(['guild-1' => 'session-1', 'guild-2' => null]);

    expect(VoiceSessions::sessionId($discord, 'guild-1'))->toBe('session-1');
    expect(VoiceSessions::sessionId($discord, 'guild-2'))->toBeNull();
    expect(VoiceSessions::sessionId($discord, 'guild-3'))->toBeNull();
});

it('remembers, invalidates and forgets a session in the array', function (): void {
    $discord = makeDiscordWithArrayForVoiceSessionsTest();

    VoiceSessions::remember($discord, 'guild-1', 'session-1');
    expect($discord->voice_sessions)->toBe(['guild-1' => 'session-1']);

    VoiceSessions::invalidate($discord, 'guild-1');
    expect($discord->voice_sessions)->toBe(['guild-1' => null]);
    expect(VoiceSessions::sessionId($discord, 'guild-1'))->toBeNull();

    VoiceSessions::forget($discord, 'guild-1');
    expect($discord->voice_sessions)->toBe([]);
});

// ── A repository, from DiscordPHP 10.66 ────────────────────────────────────

it('reads a session id from the session DiscordPHP recorded', function (): void {
    $discord = makeDiscordWithRepositoryForVoiceSessionsTest();
    recordVoiceSessionForVoiceSessionsTest($discord, 'guild-1', 'session-1');

    expect(VoiceSessions::sessionId($discord, 'guild-1'))->toBe('session-1');
    expect(VoiceSessions::sessionId($discord, 'guild-2'))->toBeNull();
});

it('leaves recording a session to DiscordPHP', function (): void {
    $discord = makeDiscordWithRepositoryForVoiceSessionsTest();

    VoiceSessions::remember($discord, 'guild-1', 'session-1');

    expect($discord->voice_sessions->get('guild_id', 'guild-1'))->toBeNull();
});

it('keeps an invalidated session, without its session id', function (): void {
    $discord = makeDiscordWithRepositoryForVoiceSessionsTest();
    recordVoiceSessionForVoiceSessionsTest($discord, 'guild-1', 'session-1');

    VoiceSessions::invalidate($discord, 'guild-1');
    VoiceSessions::invalidate($discord, 'guild-2');

    $session = $discord->voice_sessions->get('guild_id', 'guild-1');
    expect($session)->toBeInstanceOf(VoiceSession::class);
    expect($session->session_id)->toBeNull();
    expect($session->token)->toBe('voice-token');
    expect($session->isResumable())->toBeFalse();
    expect(VoiceSessions::sessionId($discord, 'guild-1'))->toBeNull();
    expect($discord->voice_sessions->get('guild_id', 'guild-2'))->toBeNull('there was no session to invalidate');
});

it('forgets a session', function (): void {
    $discord = makeDiscordWithRepositoryForVoiceSessionsTest();
    recordVoiceSessionForVoiceSessionsTest($discord, 'guild-1', 'session-1');

    VoiceSessions::forget($discord, 'guild-1');

    expect($discord->voice_sessions->get('guild_id', 'guild-1'))->toBeNull();
    expect(VoiceSessions::sessionId($discord, 'guild-1'))->toBeNull();
});

it('holds a remembered session through a sweep, when the cache answers later', function (): void {
    // A networked cache answers after the voice gateway has built its frame, so the session has to be in
    // memory when the gateway reads it, and a sweep lets go of every part nothing else holds.
    $discord = makeDiscordWithRepositoryForVoiceSessionsTest(new CacheConfig(makeLaterCacheForVoiceSessionsTest(), sweep: true));
    recordVoiceSessionForVoiceSessionsTest($discord, 'guild-1', 'session-1');
    recordVoiceSessionForVoiceSessionsTest($discord, 'guild-2', 'session-2');

    VoiceSessions::remember($discord, 'guild-1', 'session-1');
    $discord->voice_sessions->cache->sweep();

    expect(VoiceSessions::sessionId($discord, 'guild-1'))->toBe('session-1');
    expect(VoiceSessions::sessionId($discord, 'guild-2'))->toBeNull('never remembered, so the sweep let go of it');
});

it('lets go of a session once it is forgotten', function (): void {
    $discord = makeDiscordWithRepositoryForVoiceSessionsTest();
    $session = \WeakReference::create(recordVoiceSessionForVoiceSessionsTest($discord, 'guild-1', 'session-1'));

    VoiceSessions::remember($discord, 'guild-1', 'session-1');
    VoiceSessions::forget($discord, 'guild-1');

    expect($session->get())->toBeNull();
});

it('lets go of a session DiscordPHP removed', function (): void {
    $discord = makeDiscordWithRepositoryForVoiceSessionsTest();
    $session = \WeakReference::create(recordVoiceSessionForVoiceSessionsTest($discord, 'guild-1', 'session-1'));
    VoiceSessions::remember($discord, 'guild-1', 'session-1');

    // The bot left the channel: DiscordPHP removes its session before the manager hears of it.
    $discord->voice_sessions->cache->delete('guild-1');
    VoiceSessions::remember($discord, 'guild-1', 'session-1');

    expect($session->get())->toBeNull();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @param array<string, ?string> $sessions
 */
function makeDiscordWithArrayForVoiceSessionsTest(array $sessions = []): Discord
{
    $discord = (new \ReflectionClass(DiscordForVoiceSessionsTest::class))->newInstanceWithoutConstructor();
    $discord->voice_sessions = $sessions;

    return $discord;
}

/**
 * A client whose voice sessions are DiscordPHP's repository, built without connecting. Skips the test when
 * the installed DiscordPHP is older than 10.66.
 */
function makeDiscordWithRepositoryForVoiceSessionsTest(?CacheConfig $cacheConfig = null): Discord
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
 * Records a session as DiscordPHP does, from the bot's voice state and the voice server.
 */
function recordVoiceSessionForVoiceSessionsTest(Discord $discord, string $guildId, string $sessionId): Part
{
    $session = $discord->getFactory()->part(VoiceSession::class, [
        'guild_id' => $guildId,
        'channel_id' => 'channel-1',
        'user_id' => 'bot-user',
        'session_id' => $sessionId,
        'token' => 'voice-token',
        'endpoint' => 'voice.example.test:443',
    ], true);
    $discord->voice_sessions->cache->set($guildId, $session);

    return $session;
}

/**
 * A cache that keeps nothing and answers reads only later, as a networked cache does.
 */
function makeLaterCacheForVoiceSessionsTest(): CacheInterface
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
 * A client that keeps voice sessions in an array, as DiscordPHP did before 10.66, whichever DiscordPHP is
 * installed.
 */
final class DiscordForVoiceSessionsTest extends Discord
{
    public array $voice_sessions = [];
}
