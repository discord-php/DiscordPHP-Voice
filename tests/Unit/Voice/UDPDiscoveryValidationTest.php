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
use Discord\Voice\Client;
use Discord\Voice\Client\UDP;
use Discord\Voice\Client\WS;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ratchet\Client\WebSocket;

// VULN-11 regression: UDP IP discovery validates the discovered IP/port before
// sending the SELECT_PROTOCOL message to the voice gateway WebSocket.

it('invalid IP — WS select protocol is NOT sent', function (): void {
    $sent = [];
    $udp = makeUdpForDiscoveryTest($this, $sent);

    $udp->decodeOnce();
    $udp->emit('message', [makeDiscoveryPacket('999.999.999.999', 1234)]);

    expect($sent)->toBeEmpty();
});

it('port 0 — WS select protocol is NOT sent', function (): void {
    $sent = [];
    $udp = makeUdpForDiscoveryTest($this, $sent);

    $udp->decodeOnce();
    $udp->emit('message', [makeDiscoveryPacket('1.2.3.4', 0)]);

    expect($sent)->toBeEmpty();
});

it('port 65536 overflows to 0 — WS select protocol is NOT sent', function (): void {
    // pack('n', 65536) wraps to 0x0000, so the unpacked port is 0, which is < 1.
    $sent = [];
    $udp = makeUdpForDiscoveryTest($this, $sent);

    $udp->decodeOnce();
    $udp->emit('message', [makeDiscoveryPacket('1.2.3.4', 65536)]);

    expect($sent)->toBeEmpty();
});

it('valid IP and valid port — WS select protocol IS sent', function (): void {
    $sent = [];
    $udp = makeUdpForDiscoveryTest($this, $sent);

    $udp->decodeOnce();
    $udp->emit('message', [makeDiscoveryPacket('1.2.3.4', 1234)]);

    expect($sent)->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Builds a wired UDP instance (no real socket) and captures WS gateway sends.
 *
 * Uses a real WS instance (no constructor) with a mocked Ratchet WebSocket
 * injected as the socket, matching the pattern used throughout Feature tests.
 *
 * @param array<int, string> $sent Populated by reference with each raw JSON payload
 *
 * @return UDP
 */
function makeUdpForDiscoveryTest(TestCase $test, array &$sent): UDP
{
    // Discord stub — only needs getLogger().
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $loggerProp = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProp->setAccessible(true);
    $loggerProp->setValue($discord, new NullLogger());

    // VoiceClient stub — only needs ssrc and discord.
    $vc = (new \ReflectionClass(Client::class))->newInstanceWithoutConstructor();
    $vcSsrcProp = new \ReflectionProperty(Client::class, 'ssrc');
    $vcSsrcProp->setAccessible(true);
    $vcSsrcProp->setValue($vc, null);

    $vcDiscordProp = new \ReflectionProperty(Client::class, 'discord');
    $vcDiscordProp->setAccessible(true);
    $vcDiscordProp->setValue($vc, $discord);

    // WS instance without constructor; inject vc, discord, and a mock socket.
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();

    $wsVcProp = new \ReflectionProperty(WS::class, 'vc');
    $wsVcProp->setAccessible(true);
    $wsVcProp->setValue($ws, $vc);

    $wsDiscordProp = new \ReflectionProperty(WS::class, 'discord');
    $wsDiscordProp->setAccessible(true);
    $wsDiscordProp->setValue($ws, $discord);

    // Ratchet WebSocket mock — captures raw send() payloads.
    $socket = invokeUdpDiscoveryTestMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send'])
        ->getMock();
    $socket->method('send')->willReturnCallback(function (string $payload) use (&$sent): void {
        $sent[] = $payload;
    });

    $wsSocketProp = new \ReflectionProperty(WS::class, 'socket');
    $wsSocketProp->setAccessible(true);
    $wsSocketProp->setValue($ws, $socket);

    // UDP without constructor; inject the WS instance.
    $udp = (new \ReflectionClass(UDP::class))->newInstanceWithoutConstructor();
    $udpWsProp = new \ReflectionProperty(UDP::class, 'ws');
    $udpWsProp->setAccessible(true);
    $udpWsProp->setValue($udp, $ws);

    return $udp;
}

/**
 * Packs an IP-discovery response packet as Discord's voice gateway sends it.
 *
 * Format (per Discord docs):
 *   CC  Type    2 bytes  (0x00, 0x02 = response)
 *   n   Length  2 bytes
 *   N   SSRC    4 bytes
 *   A64 Address 64 bytes (null-padded)
 *   n   Port    2 bytes
 */
function makeDiscoveryPacket(string $ip, int $port): string
{
    return pack('CCnNA64n', 0x00, 0x02, 74, 12345, str_pad($ip, 64, "\0"), $port);
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeUdpDiscoveryTestMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}
