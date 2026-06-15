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
use Discord\Voice\Client\Packet;
use Discord\Voice\VoiceClient;
use Psr\Log\NullLogger;

/**
 * Build a VoiceClient without invoking its constructor, inject the ssrcToUserId
 * map via reflection, and expose the private resolveDaveRemoteUserId method.
 */
function makeVoiceClientForSSRC(): array
{
    $voiceClient = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, new NullLogger());

    $voiceClient->discord = $discord;

    $ssrcMapProp = new \ReflectionProperty(VoiceClient::class, 'ssrcToUserId');
    $ssrcMapProp->setAccessible(true);

    $resolveMethod = new \ReflectionMethod(VoiceClient::class, 'resolveDaveRemoteUserId');
    $resolveMethod->setAccessible(true);

    return [$voiceClient, $ssrcMapProp, $resolveMethod];
}

/**
 * Create a Packet stub that returns the given SSRC from getSSRC().
 */
function makePacketWithSSRC(int $ssrc): Packet
{
    $packet = (new \ReflectionClass(Packet::class))->newInstanceWithoutConstructor();

    $prop = new \ReflectionProperty(Packet::class, 'ssrc');
    $prop->setAccessible(true);
    $prop->setValue($packet, $ssrc);

    return $packet;
}

it('resolves a known SSRC to its user ID', function (): void {
    [$vc, $mapProp, $resolve] = makeVoiceClientForSSRC();

    $mapProp->setValue($vc, [12345 => '111222333444555666']);

    $result = $resolve->invoke($vc, makePacketWithSSRC(12345));

    expect($result)->toBe('111222333444555666');
});

it('returns null for an unknown SSRC', function (): void {
    [$vc, $mapProp, $resolve] = makeVoiceClientForSSRC();

    $mapProp->setValue($vc, []);

    $result = $resolve->invoke($vc, makePacketWithSSRC(99999));

    expect($result)->toBeNull();
});

it('resolves the correct user ID when multiple users are registered', function (): void {
    [$vc, $mapProp, $resolve] = makeVoiceClientForSSRC();

    $map = [
        100 => 'user-alpha',
        200 => 'user-beta',
        300 => 'user-gamma',
    ];
    $mapProp->setValue($vc, $map);

    expect($resolve->invoke($vc, makePacketWithSSRC(100)))->toBe('user-alpha');
    expect($resolve->invoke($vc, makePacketWithSSRC(200)))->toBe('user-beta');
    expect($resolve->invoke($vc, makePacketWithSSRC(300)))->toBe('user-gamma');
});

it('returns null after a user is removed from the SSRC map', function (): void {
    [$vc, $mapProp, $resolve] = makeVoiceClientForSSRC();

    $map = [77777 => 'soon-to-leave'];
    $mapProp->setValue($vc, $map);

    // Confirm it resolves before removal.
    expect($resolve->invoke($vc, makePacketWithSSRC(77777)))->toBe('soon-to-leave');

    // Simulate disconnect: remove from map.
    unset($map[77777]);
    $mapProp->setValue($vc, $map);

    expect($resolve->invoke($vc, makePacketWithSSRC(77777)))->toBeNull();
});
