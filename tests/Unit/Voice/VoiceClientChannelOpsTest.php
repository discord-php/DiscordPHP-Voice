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
use Discord\Voice\VoiceClient;
use Discord\WebSockets\Op;
use Psr\Log\NullLogger;

it('switchChannel(null) sets userClose flag and dispatches OP_UPDATE_VOICE_STATE with null channel_id', function (): void {
    [$vc, $discord] = makeVcWithDiscordCapturing();
    setVcChannel($vc, makeVoiceChannelFixture('guild-7', 'chan-1'));
    setVcMuteDeafProps($vc, false, false);

    $vc->switchChannel(null);

    $userCloseProp = new \ReflectionProperty(VoiceClient::class, 'userClose');
    $userCloseProp->setAccessible(true);

    expect($userCloseProp->getValue($vc))->toBeTrue()
        ->and($discord->sent)->toHaveCount(1)
        ->and($discord->sent[0]->op)->toBe(Op::OP_UPDATE_VOICE_STATE)
        ->and($discord->sent[0]->d['guild_id'])->toBe('guild-7')
        ->and($discord->sent[0]->d['channel_id'])->toBeNull()
        ->and($discord->sent[0]->d['self_mute'])->toBeFalse()
        ->and($discord->sent[0]->d['self_deaf'])->toBeFalse();
});

it('switchChannel(channel) updates internal channel and emits new channel_id', function (): void {
    [$vc, $discord] = makeVcWithDiscordCapturing();
    setVcChannel($vc, makeVoiceChannelFixture('guild-2', 'old-chan'));
    setVcMuteDeafProps($vc, true, true);

    $newChannel = makeVoiceChannelFixture('guild-2', 'new-chan');
    $vc->switchChannel($newChannel);

    expect($vc->channel)->toBe($newChannel)
        ->and($discord->sent[0]->d['channel_id'])->toBe('new-chan')
        ->and($discord->sent[0]->d['self_mute'])->toBeTrue()
        ->and($discord->sent[0]->d['self_deaf'])->toBeTrue();
});

it('switchChannel rejects a non-voice channel', function (): void {
    [$vc, $discord] = makeVcWithDiscordCapturing();
    setVcChannel($vc, makeVoiceChannelFixture('guild-1', 'cur'));

    $textChannel = makeChannelFixture('guild-1', 'text-chan', Channel::TYPE_GUILD_TEXT);

    expect(fn () => $vc->switchChannel($textChannel))
        ->toThrow(\InvalidArgumentException::class, 'voice channel');
    expect($discord->sent)->toBe([]);
});

it('disconnect() delegates to switchChannel(null) and returns the client', function (): void {
    [$vc, $discord] = makeVcWithDiscordCapturing();
    setVcChannel($vc, makeVoiceChannelFixture('guild-99', 'c'));
    setVcMuteDeafProps($vc, false, false);

    $result = $vc->disconnect();

    expect($result)->toBe($vc)
        ->and($discord->sent)->toHaveCount(1)
        ->and($discord->sent[0]->d['channel_id'])->toBeNull();
});

it('mainSend forwards the payload to Discord::send', function (): void {
    [$vc, $discord] = makeVcWithDiscordCapturing();

    $r = new \ReflectionMethod(VoiceClient::class, 'mainSend');
    $r->setAccessible(true);
    $r->invoke($vc, ['hello' => 'world']);

    expect($discord->sent)->toBe([['hello' => 'world']]);
});

// Helpers

function makeVcWithDiscordCapturing(): array
{
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();

    $discord = new class extends Discord {
        public array $sent = [];
        public function __construct()
        {
        }
        public function send(object|array $data, bool $force = false): void
        {
            $this->sent[] = $data;
        }
        public function getLogger(): \Psr\Log\LoggerInterface
        {
            return new NullLogger();
        }
    };

    $vc->discord = $discord;

    return [$vc, $discord];
}

function makeVoiceChannelFixture(string $guildId, string $channelId): Channel
{
    return makeChannelFixture($guildId, $channelId, Channel::TYPE_GUILD_VOICE);
}

function makeChannelFixture(string $guildId, string $channelId, int $type): Channel
{
    $channel = (new \ReflectionClass(Channel::class))->newInstanceWithoutConstructor();
    $attrs = new \ReflectionProperty(Channel::class, 'attributes');
    $attrs->setAccessible(true);
    $attrs->setValue($channel, ['guild_id' => $guildId, 'id' => $channelId, 'type' => $type]);

    return $channel;
}

function setVcChannel(VoiceClient $vc, Channel $channel): void
{
    $vc->channel = $channel;
}

function setVcMuteDeafProps(VoiceClient $vc, bool $mute, bool $deaf): void
{
    $m = new \ReflectionProperty(VoiceClient::class, 'mute');
    $m->setAccessible(true);
    $m->setValue($vc, $mute);
    $d = new \ReflectionProperty(VoiceClient::class, 'deaf');
    $d->setAccessible(true);
    $d->setValue($vc, $deaf);
}
