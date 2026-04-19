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
use Discord\Voice\Client\UDP;
use Discord\Voice\Client\WS;
use Discord\Voice\Dave\Runtime;
use Discord\Voice\Dave\State;
use Discord\Voice\VoiceClient;
use Psr\Log\NullLogger;

afterEach(function (): void {
    Runtime::reset();
});

it('encrypts and decrypts pass through when the DAVE protocol is disabled', function (): void {
    $voiceClient = makeVoiceClientWithProtocolVersion(0);

    $this->assertSame('audio', $voiceClient->encryptDaveFrame('audio'));
    $this->assertSame('audio', $voiceClient->decryptDaveFrame('audio'));
});

it('uses runtime callbacks when the DAVE protocol is enabled', function (): void {
    Runtime::configureCallbacks(
        fn (string $frame, int $protocolVersion): ?string => "enc:{$protocolVersion}:{$frame}",
        fn (string $frame, int $protocolVersion): string => "dec:{$protocolVersion}:{$frame}"
    );

    $voiceClient = makeVoiceClientWithProtocolVersion(1);

    $this->assertSame('enc:1:audio', $voiceClient->encryptDaveFrame('audio'));
    $this->assertSame('dec:1:audio', $voiceClient->decryptDaveFrame('audio'));
});

it('returns false when the runtime cannot decrypt with the DAVE protocol enabled', function (): void {
    Runtime::configureCallbacks(
        null,
        fn (string $frame, int $protocolVersion): false => false
    );

    $voiceClient = makeVoiceClientWithProtocolVersion(1);

    $this->assertFalse($voiceClient->decryptDaveFrame('audio'));
});

function makeVoiceClientWithProtocolVersion(int $protocolVersion): VoiceClient
{
    $voiceClient = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
    $udp = (new \ReflectionClass(UDP::class))->newInstanceWithoutConstructor();

    $state = new State();
    $state->setProtocolVersion($protocolVersion);

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, new NullLogger());

    $daveStateProperty = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProperty->setAccessible(true);
    $daveStateProperty->setValue($ws, $state);

    $udp->ws = $ws;
    $voiceClient->udp = $udp;
    $voiceClient->discord = $discord;

    return $voiceClient;
}
