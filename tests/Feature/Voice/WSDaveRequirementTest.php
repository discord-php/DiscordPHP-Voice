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
use Discord\Voice\Client;
use Discord\Voice\Client\WS;
use Discord\Voice\Dave\Runtime;
use Discord\Voice\Dave\Runtime as DaveRuntime;
use Discord\Voice\Exceptions\Libraries\LibDaveNotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

afterEach(function (): void {
    Runtime::reset();
});

it('throws LibDaveNotFoundException when libdave is unavailable', function (): void {
    Runtime::configureCallbacks(availabilityOverride: false);

    [$vc, $discord, $data] = makeWsForRequirementTest($this);

    expect(fn () => new WS($vc, $discord, $data))->toThrow(LibDaveNotFoundException::class);
});

it('exception message contains the Discord announcement URL', function (): void {
    Runtime::configureCallbacks(availabilityOverride: false);

    [$vc, $discord, $data] = makeWsForRequirementTest($this);

    try {
        new WS($vc, $discord, $data);
        $this->fail('Expected LibDaveNotFoundException was not thrown.');
    } catch (LibDaveNotFoundException $e) {
        expect($e->getMessage())->toContain('discord.com/developers/docs/change-log');
    }
});

it('exception message contains libdave installation hint', function (): void {
    Runtime::configureCallbacks(availabilityOverride: false);

    [$vc, $discord, $data] = makeWsForRequirementTest($this);

    try {
        new WS($vc, $discord, $data);
        $this->fail('Expected LibDaveNotFoundException was not thrown.');
    } catch (LibDaveNotFoundException $e) {
        $message = $e->getMessage();
        expect(
            str_contains($message, 'github.com/discord/libdave') ||
            str_contains($message, 'setup-libdave.sh')
        )->toBeTrue();
    }
});

it('does NOT throw LibDaveNotFoundException when libdave IS available', function (): void {
    if (! DaveRuntime::isAvailable()) {
        $this->markTestSkipped('Requires libdave to be available.');
    }

    [$vc, $discord, $data] = makeWsForRequirementTest($this);

    try {
        new WS($vc, $discord, $data);
    } catch (LibDaveNotFoundException $e) {
        $this->fail('LibDaveNotFoundException should not be thrown when libdave is available: '.$e->getMessage());
    } catch (\Throwable) {
        // Any other exception (e.g., async network/connector error) is acceptable;
        // we only care that LibDaveNotFoundException was NOT the cause.
    }

    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Builds a minimal set of constructor arguments for WS suitable for requirement tests.
 *
 * Returns [$voiceClient, $discord, $data].
 *
 * @return array{0: Client, 1: Discord, 2: array<string, string>}
 */
function makeWsForRequirementTest(TestCase $test): array
{
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, new NullLogger());

    $voiceClient = invokeRequirementWsMethod($test, 'getMockBuilder', [Client::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['emit'])
        ->getMock();

    $channel = (new \ReflectionClass(Channel::class))->newInstanceWithoutConstructor();
    $attributesProperty = new \ReflectionProperty(Channel::class, 'attributes');
    $attributesProperty->setAccessible(true);
    $attributesProperty->setValue($channel, ['guild_id' => '987654321', 'id' => 'channel-1']);
    $voiceClient->channel = $channel;

    $data = [
        'endpoint'   => 'voice.discord.gg',
        'user_id'    => '123456789',
        'guild_id'   => '987654321',
        'token'      => 'test-token',
        'session_id' => 'test-session',
    ];

    return [$voiceClient, $discord, $data];
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeRequirementWsMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}
