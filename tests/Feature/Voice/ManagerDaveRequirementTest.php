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
use Discord\Voice\Dave\Runtime;
use Discord\Voice\Dave\Runtime as DaveRuntime;
use Discord\Voice\Exceptions\Libraries\LibDaveNotFoundException;
use Discord\Voice\Manager;
use Psr\Log\NullLogger;

afterEach(function (): void {
    Runtime::reset();
});

it('throws LibDaveNotFoundException when libdave is unavailable', function (): void {
    Runtime::configureCallbacks(availabilityOverride: false);

    $discord = makeDiscordForManagerRequirementTest();

    expect(fn () => new Manager($discord))->toThrow(LibDaveNotFoundException::class);
});

it('exception message contains the Discord announcement URL', function (): void {
    Runtime::configureCallbacks(availabilityOverride: false);

    $discord = makeDiscordForManagerRequirementTest();

    try {
        new Manager($discord);
        $this->fail('Expected LibDaveNotFoundException was not thrown.');
    } catch (LibDaveNotFoundException $e) {
        expect($e->getMessage())->toContain('discord.com/developers/docs/change-log');
    }
});

it('exception message contains libdave installation hint', function (): void {
    Runtime::configureCallbacks(availabilityOverride: false);

    $discord = makeDiscordForManagerRequirementTest();

    try {
        new Manager($discord);
        $this->fail('Expected LibDaveNotFoundException was not thrown.');
    } catch (LibDaveNotFoundException $e) {
        $message = $e->getMessage();
        expect(
            str_contains($message, 'github.com/discord/libdave') ||
            str_contains($message, 'setup-libdave.sh')
        )->toBeTrue();
    }
});

it('does NOT throw when libdave IS available', function (): void {
    if (! DaveRuntime::isAvailable()) {
        $this->markTestSkipped('Requires libdave to be available.');
    }

    $discord = makeDiscordForManagerRequirementTest();

    try {
        new Manager($discord);
    } catch (LibDaveNotFoundException $e) {
        $this->fail('LibDaveNotFoundException should not be thrown when libdave is available: '.$e->getMessage());
    }

    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Builds a minimal Discord instance suitable for Manager requirement tests.
 */
function makeDiscordForManagerRequirementTest(): Discord
{
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, new NullLogger());

    return $discord;
}
