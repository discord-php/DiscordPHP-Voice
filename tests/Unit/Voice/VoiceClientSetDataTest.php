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
use Discord\Voice\VoiceClient;
use Psr\Log\NullLogger;

it('merges new data into existing data', function (): void {
    $client = makeVoiceClientForSetDataTest();

    $dataProp = new \ReflectionProperty(VoiceClient::class, 'data');
    $dataProp->setAccessible(true);
    $dataProp->setValue($client, ['existing' => 'val']);

    $client->setData(['foo' => 'bar']);

    $data = $dataProp->getValue($client);

    expect($data)->toBe(['existing' => 'val', 'foo' => 'bar']);
});

it('does not call boot when required keys are missing', function (): void {
    $client = $this->getMockBuilder(VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['boot'])
        ->getMock();

    $client->expects($this->never())->method('boot');

    $dataProp = new \ReflectionProperty(VoiceClient::class, 'data');
    $dataProp->setAccessible(true);
    $dataProp->setValue($client, []);

    $client->setData(['token' => 'tok']);
});

it('calls boot and sets endpoint and dnsConfig when all required keys are present', function (): void {
    $client = $this->getMockBuilder(VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['boot'])
        ->getMock();

    $client->expects($this->once())->method('boot');

    $dataProp = new \ReflectionProperty(VoiceClient::class, 'data');
    $dataProp->setAccessible(true);
    $dataProp->setValue($client, []);

    $discordProp = new \ReflectionProperty(VoiceClient::class, 'discord');
    $discordProp->setAccessible(true);
    $discordProp->setValue($client, makeDiscordForSetDataTest());

    $client->setData([
        'token' => 'my-token',
        'endpoint' => 'gateway.discord.gg:443',
        'session' => 'session-id',
        'dnsConfig' => '8.8.8.8',
    ]);

    expect($client->endpoint)->toBe('gateway.discord.gg')
        ->and($client->dnsConfig)->toBe('8.8.8.8');
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeVoiceClientForSetDataTest(): VoiceClient
{
    $client = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();

    $dataProp = new \ReflectionProperty(VoiceClient::class, 'data');
    $dataProp->setAccessible(true);
    $dataProp->setValue($client, []);

    return $client;
}

function makeDiscordForSetDataTest(): Discord
{
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, new NullLogger());

    return $discord;
}
