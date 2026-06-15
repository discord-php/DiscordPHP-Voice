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
use Discord\Voice\Client;
use Discord\Voice\Dave\Runtime;
use Discord\Voice\Exceptions\Channels\CantJoinMoreThanOneChannelException;
use Discord\Voice\Exceptions\Channels\CantSpeakInChannelException;
use Discord\Voice\Exceptions\Channels\ChannelMustAllowVoiceException;
use Discord\Voice\Exceptions\Channels\EnterChannelDeniedException;
use Discord\Voice\Manager;
use Discord\WebSockets\VoicePayload;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\Promise\PromiseInterface;

afterEach(function (): void {
    Runtime::reset();
});

it('rejects with ChannelMustAllowVoiceException when the channel is not voice-based', function (): void {
    Runtime::configureCallbacks(availabilityOverride: true);

    $sent = [];
    $discord = makeDiscordForJoinChannelTest($this, $sent);
    $manager = new Manager($discord);
    $channel = makeChannelForJoinChannelTest($this, 'guild-1', voiceBased: false, connect: true, speak: true);
    $voiceSessions = [];

    $promise = $manager->joinChannel($channel, $discord, $voiceSessions);

    expectPromiseRejectsWith($promise, ChannelMustAllowVoiceException::class);
    expect($sent)->toBe([]);
    expect($manager->clients)->toBe([]);
});

it('rejects with EnterChannelDeniedException when the bot lacks Connect permission', function (): void {
    Runtime::configureCallbacks(availabilityOverride: true);

    $sent = [];
    $discord = makeDiscordForJoinChannelTest($this, $sent);
    $manager = new Manager($discord);
    $channel = makeChannelForJoinChannelTest($this, 'guild-1', voiceBased: true, connect: false, speak: true);
    $voiceSessions = [];

    $promise = $manager->joinChannel($channel, $discord, $voiceSessions);

    expectPromiseRejectsWith($promise, EnterChannelDeniedException::class);
    expect($sent)->toBe([]);
    expect($manager->clients)->toBe([]);
});

it('rejects with CantSpeakInChannelException when the bot lacks Speak permission and is not muted', function (): void {
    Runtime::configureCallbacks(availabilityOverride: true);

    $sent = [];
    $discord = makeDiscordForJoinChannelTest($this, $sent);
    $manager = new Manager($discord);
    $channel = makeChannelForJoinChannelTest($this, 'guild-1', voiceBased: true, connect: true, speak: false);
    $voiceSessions = [];

    $promise = $manager->joinChannel($channel, $discord, $voiceSessions, mute: false);

    expectPromiseRejectsWith($promise, CantSpeakInChannelException::class);
    expect($sent)->toBe([]);
    expect($manager->clients)->toBe([]);
});

it('does NOT reject with CantSpeakInChannelException when bot lacks Speak but joins muted', function (): void {
    Runtime::configureCallbacks(availabilityOverride: true);

    $sent = [];
    $discord = makeDiscordForJoinChannelTest($this, $sent);
    $manager = new Manager($discord);
    $channel = makeChannelForJoinChannelTest($this, 'guild-1', voiceBased: true, connect: true, speak: false);
    $voiceSessions = [];

    $promise = $manager->joinChannel($channel, $discord, $voiceSessions, mute: true);

    expect($promise)->toBeInstanceOf(PromiseInterface::class);
    expect($sent)->toHaveCount(1);
    expect($manager->clients)->toHaveKey('guild-1');
});

it('rejects with CantJoinMoreThanOneChannelException when a client already exists for the guild', function (): void {
    Runtime::configureCallbacks(availabilityOverride: true);

    $sent = [];
    $discord = makeDiscordForJoinChannelTest($this, $sent);
    $manager = new Manager($discord);

    // Pre-populate clients map for this guild.
    $existing = $this->getMockBuilder(Client::class)
        ->disableOriginalConstructor()
        ->getMock();
    $manager->clients['guild-1'] = $existing;

    $channel = makeChannelForJoinChannelTest($this, 'guild-1', voiceBased: true, connect: true, speak: true);
    $voiceSessions = [];

    $promise = $manager->joinChannel($channel, $discord, $voiceSessions);

    expectPromiseRejectsWith($promise, CantJoinMoreThanOneChannelException::class);
    expect($sent)->toBe([]);
    expect($manager->clients['guild-1'])->toBe($existing);
});

it('sends OP_UPDATE_VOICE_STATE and registers a client on happy path', function (): void {
    Runtime::configureCallbacks(availabilityOverride: true);

    $sent = [];
    $discord = makeDiscordForJoinChannelTest($this, $sent);
    $manager = new Manager($discord);
    $channel = makeChannelForJoinChannelTest($this, 'guild-1', voiceBased: true, connect: true, speak: true);
    $voiceSessions = [];

    $promise = $manager->joinChannel($channel, $discord, $voiceSessions, mute: false, deaf: true);

    expect($promise)->toBeInstanceOf(PromiseInterface::class);
    expect($manager->clients)->toHaveKey('guild-1');
    expect($manager->clients['guild-1'])->toBeInstanceOf(Client::class);

    expect($sent)->toHaveCount(1);
    $payload = $sent[0];
    expect($payload)->toBeInstanceOf(VoicePayload::class);

    $encoded = json_decode(json_encode($payload), true);
    expect($encoded['op'])->toBe(4); // OP_UPDATE_VOICE_STATE
    expect($encoded['d'])->toBe([
        'guild_id' => 'guild-1',
        'channel_id' => 'channel-1',
        'self_mute' => false,
        'self_deaf' => true,
    ]);
});

it('propagates self_mute and self_deaf flags through the gateway payload', function (): void {
    Runtime::configureCallbacks(availabilityOverride: true);

    $sent = [];
    $discord = makeDiscordForJoinChannelTest($this, $sent);
    $manager = new Manager($discord);
    $channel = makeChannelForJoinChannelTest($this, 'guild-2', voiceBased: true, connect: true, speak: true);
    $voiceSessions = [];

    $manager->joinChannel($channel, $discord, $voiceSessions, mute: true, deaf: false);

    $encoded = json_decode(json_encode($sent[0]), true);
    expect($encoded['d']['self_mute'])->toBeTrue();
    expect($encoded['d']['self_deaf'])->toBeFalse();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Builds a partial Discord mock that captures payloads sent through send().
 *
 * @param array<int, mixed> $sent Captured payloads, populated by reference.
 */
function makeDiscordForJoinChannelTest(TestCase $test, array &$sent): Discord
{
    $builder = invokeJoinChannelTestMethod($test, 'getMockBuilder', [Discord::class]);
    $discord = $builder
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

    return $discord;
}

/**
 * Builds a partial Channel mock with stubbed permission/voice predicates.
 */
function makeChannelForJoinChannelTest(
    TestCase $test,
    string $guildId,
    bool $voiceBased,
    bool $connect,
    bool $speak,
): Channel {
    $builder = invokeJoinChannelTestMethod($test, 'getMockBuilder', [Channel::class]);
    $channel = $builder
        ->disableOriginalConstructor()
        ->onlyMethods(['isVoiceBased', 'getBotPermissions'])
        ->getMock();

    $channel->method('isVoiceBased')->willReturn($voiceBased);
    $channel->method('getBotPermissions')->willReturn(makeRolePermissionForJoinChannelTest($connect, $speak));

    $attrProp = new \ReflectionProperty(Part::class, 'attributes');
    $attrProp->setAccessible(true);
    $attrProp->setValue($channel, [
        'guild_id' => $guildId,
        'id' => 'channel-1',
        'bitrate' => 64000,
    ]);

    return $channel;
}

function makeRolePermissionForJoinChannelTest(bool $connect, bool $speak): RolePermission
{
    $perm = (new \ReflectionClass(RolePermission::class))->newInstanceWithoutConstructor();

    $attrProp = new \ReflectionProperty(Part::class, 'attributes');
    $attrProp->setAccessible(true);
    $attrProp->setValue($perm, [
        'connect' => $connect,
        'speak' => $speak,
    ]);

    return $perm;
}

/**
 * Asserts that a settled promise rejected with an instance of the given class.
 */
function expectPromiseRejectsWith(PromiseInterface $promise, string $expectedClass): void
{
    $captured = null;
    $promise->then(
        function ($value) use (&$captured): void {
            $captured = ['resolved', $value];
        },
        function ($reason) use (&$captured): void {
            $captured = ['rejected', $reason];
        }
    );

    expect($captured)->not->toBeNull();
    expect($captured[0])->toBe('rejected');
    expect($captured[1])->toBeInstanceOf($expectedClass);
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeJoinChannelTestMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}
