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
use Discord\Voice\Client\WS;
use Discord\Voice\Dave\State;
use Discord\Voice\Client as VoiceClientAlias;
use Discord\WebSockets\Op;
use Discord\WebSockets\VoicePayload;
use Psr\Log\NullLogger;

it('getVoiceClient returns the wrapped VoiceClient instance', function (): void {
    $ws = makeWsForGetters();
    $vc = (new \ReflectionClass(VoiceClientAlias::class))->newInstanceWithoutConstructor();
    $vcProp = new \ReflectionProperty(WS::class, 'vc');
    $vcProp->setAccessible(true);
    $vcProp->setValue($ws, $vc);

    expect($ws->getVoiceClient())->toBe($vc);
});

it('getDaveProtocolVersion mirrors the DAVE state protocol version', function (): void {
    $ws = makeWsForGetters();
    $state = getWsDaveStateForGetters($ws);
    $state->setProtocolVersion(2);

    expect($ws->getDaveProtocolVersion())->toBe(2);
});

it('getRawKey returns the rawKey property unmodified', function (): void {
    $ws = makeWsForGetters();
    $rawKeyProp = new \ReflectionProperty(WS::class, 'rawKey');
    $rawKeyProp->setAccessible(true);
    $rawKeyProp->setValue($ws, [1, 2, 3, 4]);

    expect($ws->getRawKey())->toBe([1, 2, 3, 4]);
});

it('handleCloseVoiceDisconnected logs the close opcode without raising', function (): void {
    $ws = makeWsForGetters();

    $payload = VoicePayload::new(Op::VOICE_HEARTBEAT, []);

    // Should be a no-op beyond the debug log call.
    expect(fn () => $ws->handleCloseVoiceDisconnected($payload))->not->toThrow(\Throwable::class);
});

it('handleFlags / handlePlatform / handleUndocumented are passive log-only handlers', function (): void {
    $ws = makeWsForGetters();

    $invoke = function (string $method, ?VoicePayload $data = null) use ($ws) {
        $r = new \ReflectionMethod(WS::class, $method);
        $r->setAccessible(true);

        return $r->invoke($ws, $data ?? VoicePayload::new(Op::VOICE_FLAGS, ['flags' => 0]));
    };

    expect(fn () => $invoke('handleFlags'))->not->toThrow(\Throwable::class)
        ->and(fn () => $invoke('handlePlatform', VoicePayload::new(Op::VOICE_PLATFORM, ['platform' => 0])))
            ->not->toThrow(\Throwable::class)
        ->and(fn () => $invoke('handleUndocumented', VoicePayload::new(Op::VOICE_HEARTBEAT, [])))
            ->not->toThrow(\Throwable::class);
});

// Helpers

function makeWsForGetters(): WS
{
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();

    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $loggerProp = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProp->setAccessible(true);
    $loggerProp->setValue($discord, new NullLogger());

    $discordProp = new \ReflectionProperty(WS::class, 'discord');
    $discordProp->setAccessible(true);
    $discordProp->setValue($ws, $discord);

    $stateProp = new \ReflectionProperty(WS::class, 'daveState');
    $stateProp->setAccessible(true);
    $stateProp->setValue($ws, new State());

    return $ws;
}

function getWsDaveStateForGetters(WS $ws): State
{
    $p = new \ReflectionProperty(WS::class, 'daveState');
    $p->setAccessible(true);

    return $p->getValue($ws);
}
