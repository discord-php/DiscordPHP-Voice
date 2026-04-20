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
use Discord\Parts\Channel\Channel;
use Discord\Parts\WebSockets\VoiceStateUpdate;
use Discord\Voice\Manager;

// VULN-16 regression: Manager::stateUpdate() uses strict !== to compare guild
// IDs, preventing type-coercion bypasses that were possible with loose !=.

it('stateUpdate early-returns when guild IDs differ', function (): void {
    // $this->clients is intentionally left uninitialised.  If stateUpdate
    // tries to access it before the early-return guard, PHP will throw an
    // "Uninitialized typed property" Error — proving the guard is absent.
    $manager = makeManagerWithoutClients();

    $state = makeVoiceStateUpdateWithGuildId('guild-A');
    $channel = makeChannelWithGuildId('guild-B');

    // Must not throw even though $this->clients is uninitialised.
    $manager->stateUpdate($state, $channel);

    expect(true)->toBeTrue();
});

it('stateUpdate proceeds when guild IDs match', function (): void {
    // Inject an empty clients array so getClient() returns null and the
    // method exits at the second guard (no client registered) without needing
    // a real Discord logger or client.
    $manager = makeManagerWithClients([]);

    $state = makeVoiceStateUpdateWithGuildId('guild-A');
    $channel = makeChannelWithGuildId('guild-A');

    // Must not throw; getClient() returns null → second early-return.
    $manager->stateUpdate($state, $channel);

    expect(true)->toBeTrue();
});

it('strict !== correctly rejects a type-mismatched guild ID (regression for loose !=)', function (): void {
    // With the old loose !=: "12345" != 12345 evaluates to false (equal!),
    // so stateUpdate would proceed and access uninitialised $this->clients.
    // With the fixed strict !==: "12345" !== 12345 is true (different types),
    // so stateUpdate returns early before touching $this->clients.
    $manager = makeManagerWithoutClients();

    $state = makeVoiceStateUpdateWithGuildId('12345');       // string
    $channel = makeChannelWithGuildIdRaw(12345);              // integer

    // Must not throw — correct strict guard means early return.
    $manager->stateUpdate($state, $channel);

    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Builds a Manager without constructor; $this->clients is NOT initialised.
 * Accessing it will throw an Error, which is used by some tests as a sentinel.
 */
function makeManagerWithoutClients(): Manager
{
    return (new \ReflectionClass(Manager::class))->newInstanceWithoutConstructor();
}

/**
 * Builds a Manager without constructor and injects the given clients map.
 *
 * @param array<string, \Discord\Voice\Client> $clients
 */
function makeManagerWithClients(array $clients): Manager
{
    $manager = (new \ReflectionClass(Manager::class))->newInstanceWithoutConstructor();

    $clientsProp = new \ReflectionProperty(Manager::class, 'clients');
    $clientsProp->setAccessible(true);
    $clientsProp->setValue($manager, $clients);

    return $manager;
}

/**
 * Builds a VoiceStateUpdate stub with the given guild_id string.
 */
function makeVoiceStateUpdateWithGuildId(string $guildId): VoiceStateUpdate
{
    $state = (new \ReflectionClass(VoiceStateUpdate::class))->newInstanceWithoutConstructor();

    $attrProp = new \ReflectionProperty(\Discord\Parts\Part::class, 'attributes');
    $attrProp->setAccessible(true);
    $attrProp->setValue($state, ['guild_id' => $guildId]);

    return $state;
}

/**
 * Builds a Channel stub with the given guild_id string.
 */
function makeChannelWithGuildId(string $guildId): Channel
{
    return makeChannelWithGuildIdRaw($guildId);
}

/**
 * Builds a Channel stub with a raw (possibly non-string) guild_id value.
 * Used to exercise the strict type-comparison regression test.
 *
 * @param mixed $guildId
 */
function makeChannelWithGuildIdRaw(mixed $guildId): Channel
{
    $channel = (new \ReflectionClass(Channel::class))->newInstanceWithoutConstructor();

    $attrProp = new \ReflectionProperty(\Discord\Parts\Part::class, 'attributes');
    $attrProp->setAccessible(true);
    $attrProp->setValue($channel, ['guild_id' => $guildId]);

    return $channel;
}
