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
use Discord\Voice\Client as VoiceClient;
use Discord\Voice\Client\UDP;
use Discord\Voice\Client\WS;
use Discord\Voice\Dave\State as DaveState;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ratchet\Client\WebSocket;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;

// ---------------------------------------------------------------------------
// 1. IP discovery request payload — the 74-byte packet handleSsrcSending sends
//    must conform to Discord's voice spec: type=0x0001, length=0x0046 (70),
//    SSRC big-endian, then 64 bytes for address + 2 for port (zeroed on
//    request).
// ---------------------------------------------------------------------------

it('handleSsrcSending writes the spec-mandated 74-byte discovery request', function (): void {
    $sentBytes = [];
    $timerCallbacks = [];

    $loop = $this->getMockBuilder(LoopInterface::class)->getMock();
    $loop->method('addTimer')->willReturnCallback(
        function (float $interval, callable $cb) use (&$timerCallbacks): TimerInterface {
            $timerCallbacks[] = ['interval' => $interval, 'cb' => $cb];

            return $this->getMockBuilder(TimerInterface::class)->getMock();
        }
    );

    $udp = makeUdpTransportMock($this, $sentBytes, $loop, ssrc: 0xCAFEBABE);

    $udp->handleSsrcSending();

    expect($timerCallbacks)->toHaveCount(1)
        ->and($timerCallbacks[0]['interval'])->toBe(0.1);

    // Fire the deferred timer to actually emit the packet.
    ($timerCallbacks[0]['cb'])();

    expect($sentBytes)->toHaveCount(1);
    $packet = $sentBytes[0];

    expect(strlen($packet))->toBe(74);

    $unpacked = unpack('nType/nLength/NSSRC/A64Address/nPort', $packet);
    expect($unpacked['Type'])->toBe(0x0001)
        ->and($unpacked['Length'])->toBe(70)
        ->and($unpacked['Length'])->toBe(0x46)
        ->and($unpacked['SSRC'])->toBe(0xCAFEBABE)
        ->and($unpacked['Address'])->toBe('')
        ->and($unpacked['Port'])->toBe(0);
});

// ---------------------------------------------------------------------------
// 2. IP discovery response parsing — given a response packet, decodeOnce()
//    must extract the null-terminated address and port and forward them to
//    the voice gateway via SELECT_PROTOCOL.
// ---------------------------------------------------------------------------

it('decodeOnce extracts null-terminated address and port from the response', function (): void {
    $wsSent = [];
    $udp = makeUdpTransportMock($this, $unused, loop: null, ssrc: 12345, wsSent: $wsSent);

    $udp->decodeOnce();

    // A null-padded address inside the 64-byte field must be trimmed cleanly.
    $response = pack('CCnNA64n', 0x00, 0x02, 70, 12345, str_pad('203.0.113.42', 64, "\0"), 50001);
    $udp->emit('message', [$response]);

    expect($wsSent)->toHaveCount(1);

    $payload = json_decode($wsSent[0], true);
    expect($payload['d']['protocol'])->toBe('udp')
        ->and($payload['d']['data']['address'])->toBe('203.0.113.42')
        ->and($payload['d']['data']['port'])->toBe(50001);
});

it('decodeOnce ignores a mismatched SSRC and keeps the expected one', function (): void {
    $wsSent = [];
    $udp = makeUdpTransportMock($this, $unused, loop: null, ssrc: 12345, wsSent: $wsSent);

    $udp->decodeOnce();

    // SSRC 99999 in the response does not match the configured 12345.
    $response = pack('CCnNA64n', 0x00, 0x02, 70, 99999, str_pad('1.2.3.4', 64, "\0"), 4242);
    $udp->emit('message', [$response]);

    // The SELECT_PROTOCOL message is still sent (IP/port valid) but the SSRC
    // on the voice client must remain the originally negotiated one.
    expect($wsSent)->toHaveCount(1)
        ->and($udp->ws->vc->ssrc)->toBe(12345);
});

// ---------------------------------------------------------------------------
// 3. UDP heartbeat — handleHeartbeat() schedules a periodic timer at
//    hbInterval/1000 and each tick sends a 9-byte packet (1-byte opcode +
//    8-byte little-endian sequence) and emits 'udp-heartbeat' on the VC.
// ---------------------------------------------------------------------------

it('handleHeartbeat schedules a periodic timer and increments sequence per tick', function (): void {
    $sentBytes = [];
    $captured = null;

    $loop = $this->getMockBuilder(LoopInterface::class)->getMock();
    $loop->method('addPeriodicTimer')->willReturnCallback(
        function (float $interval, callable $cb) use (&$captured): TimerInterface {
            $captured = ['interval' => $interval, 'cb' => $cb];

            return $this->getMockBuilder(TimerInterface::class)->getMock();
        }
    );

    $udp = makeUdpTransportMock($this, $sentBytes, $loop, ssrc: 1);
    $udp->hbInterval = 5000;

    $heartbeatEvents = 0;
    $udp->ws->vc->on('udp-heartbeat', function () use (&$heartbeatEvents): void {
        ++$heartbeatEvents;
    });

    $udp->handleHeartbeat();

    expect($captured)->not->toBeNull()
        ->and($captured['interval'])->toBe(5.0);

    // Drive the periodic callback twice.
    ($captured['cb'])();
    ($captured['cb'])();

    expect($sentBytes)->toHaveCount(2)
        ->and($heartbeatEvents)->toBe(2);

    // Trailing 8 bytes are the little-endian sequence; verify it increments.
    $seq0 = unpack('Pseq', substr($sentBytes[0], -8))['seq'];
    $seq1 = unpack('Pseq', substr($sentBytes[1], -8))['seq'];

    expect($seq1 - $seq0)->toBe(1);
});

// ---------------------------------------------------------------------------
// 4. sendBuffer — wraps the payload through Packet (AES-256-GCM) and sends
//    via the underlying socket. Round-trip via sodium decrypt proves the
//    bytes that hit the wire are a valid encrypted RTP packet for the
//    configured SSRC/seq/timestamp.
// ---------------------------------------------------------------------------

it('sendBuffer emits an encrypted RTP packet and round-trips through sodium', function (): void {
    if (! function_exists('sodium_crypto_aead_aes256gcm_decrypt')) {
        $this->markTestSkipped('libsodium AES-256-GCM not available.');
    }

    $sentBytes = [];
    $secretKey = str_repeat("\x42", SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    $udp = makeUdpTransportMock(
        $this,
        $sentBytes,
        loop: null,
        ssrc: 0x11223344,
        secretKey: $secretKey,
    );

    $udp->ws->vc->ready = true;
    $udp->ws->vc->seq = 7;
    $udp->ws->vc->timestamp = 960;
    $udp->ws->vc->nonce = 1;

    $payload = "\xFA\xFB\xFC\xFD"; // arbitrary "opus" frame

    $emittedPacket = null;
    $udp->ws->vc->on('packet-sent', function ($p) use (&$emittedPacket): void {
        $emittedPacket = $p;
    });

    $udp->sendBuffer($payload);

    expect($sentBytes)->toHaveCount(1)
        ->and($emittedPacket)->not->toBeNull();

    $raw = $sentBytes[0];

    // RTP header: version+flags, payload type, seq (BE), timestamp (BE), ssrc (BE).
    $header = unpack('Cv/Cpt/nseq/Nts/Nssrc', substr($raw, 0, 12));
    expect($header['v'])->toBe(0x80)
        ->and($header['pt'])->toBe(0x78)
        ->and($header['seq'])->toBe(7)
        ->and($header['ts'])->toBe(960)
        ->and($header['ssrc'])->toBe(0x11223344);

    // Trailing 4 bytes is the LE nonce counter; pad to 12 bytes for AES-GCM.
    $nonce = substr($raw, -4);
    expect($nonce)->toBe(pack('V', 1));
    $paddedNonce = str_pad($nonce, SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES, "\0", STR_PAD_RIGHT);

    $cipherWithTag = substr($raw, 12, strlen($raw) - 12 - 4);
    $decrypted = sodium_crypto_aead_aes256gcm_decrypt(
        $cipherWithTag,
        substr($raw, 0, 12),
        $paddedNonce,
        $secretKey,
    );

    expect($decrypted)->toBe($payload);
});

it('sendBuffer is a no-op when the voice client is not ready', function (): void {
    $sentBytes = [];
    $udp = makeUdpTransportMock(
        $this,
        $sentBytes,
        loop: null,
        ssrc: 1,
        secretKey: str_repeat("\x00", SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES),
    );

    $udp->ws->vc->ready = false;

    $udp->sendBuffer("\x01\x02\x03");

    expect($sentBytes)->toBeEmpty();
});

// ---------------------------------------------------------------------------
// 5. handleMessages RTCP / non-RTP-v2 filtering
//    Discord multiplexes RTCP on the same UDP port (RFC 5761). Packets with
//    payload type in 200–223 (0xC8–0xDF) or with RTP version != 2 must be
//    dropped before a Packet is constructed.
// ---------------------------------------------------------------------------

it('handleMessages drops RTCP Sender Report packets before calling handleAudioData', function (): void {
    $audioHandled = 0;
    $sentBytes = [];
    $udp = makeUdpTransportMock($this, $sentBytes, loop: null, ssrc: 1);

    $vc = $this->getMockBuilder(VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['handleAudioData'])
        ->getMock();
    $vc->expects($this->never())->method('handleAudioData');
    $vc->deaf = false;
    $udp->ws->vc = $vc;

    // RTCP SR: byte0=0x80 (RTP v2, no padding/extension), byte1=0xC8 (PT=200)
    $rtcpSr = pack('CC', 0x80, 0xC8).str_repeat("\x00", 30);

    $udp->handleMessages(str_repeat("\x00", SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES));
    $udp->emit('message', [$rtcpSr]);
});

it('handleMessages drops RTCP Receiver Report packets', function (): void {
    $sentBytes = [];
    $udp = makeUdpTransportMock($this, $sentBytes, loop: null, ssrc: 1);

    $vc = $this->getMockBuilder(VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['handleAudioData'])
        ->getMock();
    $vc->expects($this->never())->method('handleAudioData');
    $vc->deaf = false;
    $udp->ws->vc = $vc;

    // RTCP RR: byte1=0xC9 (PT=201)
    $rtcpRr = pack('CC', 0x80, 0xC9).str_repeat("\x00", 30);

    $udp->handleMessages(str_repeat("\x00", SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES));
    $udp->emit('message', [$rtcpRr]);
});

it('handleMessages drops non-RTP-v2 packets', function (): void {
    $sentBytes = [];
    $udp = makeUdpTransportMock($this, $sentBytes, loop: null, ssrc: 1);

    $vc = $this->getMockBuilder(VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['handleAudioData'])
        ->getMock();
    $vc->expects($this->never())->method('handleAudioData');
    $vc->deaf = false;
    $udp->ws->vc = $vc;

    // byte0=0x40: version=1, not RTP v2
    $nonRtpV2 = pack('CC', 0x40, 0x78).str_repeat("\x00", 30);

    $udp->handleMessages(str_repeat("\x00", SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES));
    $udp->emit('message', [$nonRtpV2]);
});

it('handleMessages passes valid RTP packets (PT=0x78) to handleAudioData', function (): void {
    $sentBytes = [];
    $udp = makeUdpTransportMock($this, $sentBytes, loop: null, ssrc: 1);

    $audioCalls = 0;
    $vc = $this->getMockBuilder(VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['handleAudioData'])
        ->getMock();
    $vc->method('handleAudioData')->willReturnCallback(function () use (&$audioCalls): void {
        $audioCalls++;
    });
    $vc->deaf = false;
    $udp->ws->vc = $vc;

    // Valid RTP v2, PT=0x78 (Discord Opus), 32 bytes minimum
    $rtpPacket = pack('CCnNN', 0x80, 0x78, 1, 0, 99).str_repeat("\x00", 20);

    $udp->handleMessages(str_repeat("\x00", SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES));
    $udp->emit('message', [$rtpPacket]);

    expect($audioCalls)->toBe(1);
});

it('handleMessages passes RTP packets with the marker bit set (PT=0xF8) to handleAudioData', function (): void {
    $sentBytes = [];
    $udp = makeUdpTransportMock($this, $sentBytes, loop: null, ssrc: 1);

    $audioCalls = 0;
    $vc = $this->getMockBuilder(VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['handleAudioData'])
        ->getMock();
    $vc->method('handleAudioData')->willReturnCallback(function () use (&$audioCalls): void {
        $audioCalls++;
    });
    $vc->deaf = false;
    $udp->ws->vc = $vc;

    // byte1=0xF8: M=1, PT=0x78 (120 & 0x7F = 120, not in 72–95 RTCP range)
    $rtpMarked = pack('CCnNN', 0x80, 0xF8, 2, 0, 99).str_repeat("\x00", 20);

    $udp->handleMessages(str_repeat("\x00", SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES));
    $udp->emit('message', [$rtpMarked]);

    expect($audioCalls)->toBe(1);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Builds a UDP instance with `send()` mocked and the WS/VC graph wired up
 * just enough for the methods under test.
 *
 * @param array<int, string>      $sentBytes Captures every raw UDP packet
 * @param array<int, string>|null $wsSent    If supplied, captures WS gateway sends
 */
function makeUdpTransportMock(
    TestCase $test,
    ?array &$sentBytes,
    ?LoopInterface $loop = null,
    ?int $ssrc = null,
    ?string $secretKey = null,
    ?array &$wsSent = null,
): UDP {
    $sentBytes ??= [];
    $loop ??= invokeUdpTransportProtected($test, 'getMockBuilder', [LoopInterface::class])->getMock();

    // Discord stub — needs logger and (optionally) loop.
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $loggerProp = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProp->setAccessible(true);
    $loggerProp->setValue($discord, new NullLogger());
    $loopProp = new \ReflectionProperty(Discord::class, 'loop');
    $loopProp->setAccessible(true);
    $loopProp->setValue($discord, $loop);

    // VoiceClient stub.
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vcSsrcProp = new \ReflectionProperty(VoiceClient::class, 'ssrc');
    $vcSsrcProp->setAccessible(true);
    $vcSsrcProp->setValue($vc, $ssrc);
    $vcDiscordProp = new \ReflectionProperty(VoiceClient::class, 'discord');
    $vcDiscordProp->setAccessible(true);
    $vcDiscordProp->setValue($vc, $discord);

    // WS stub with default DAVE state (protocolVersion=0 ⇒ encrypt path is a passthrough).
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
    $wsVcProp = new \ReflectionProperty(WS::class, 'vc');
    $wsVcProp->setAccessible(true);
    $wsVcProp->setValue($ws, $vc);
    $wsDiscordProp = new \ReflectionProperty(WS::class, 'discord');
    $wsDiscordProp->setAccessible(true);
    $wsDiscordProp->setValue($ws, $discord);

    if ($secretKey !== null) {
        $wsKeyProp = new \ReflectionProperty(WS::class, 'secretKey');
        $wsKeyProp->setAccessible(true);
        $wsKeyProp->setValue($ws, $secretKey);
    }

    $wsDaveProp = new \ReflectionProperty(WS::class, 'daveState');
    $wsDaveProp->setAccessible(true);
    $wsDaveProp->setValue($ws, new DaveState());

    if ($wsSent !== null) {
        $socket = invokeUdpTransportProtected($test, 'getMockBuilder', [WebSocket::class])
            ->disableOriginalConstructor()
            ->onlyMethods(['send'])
            ->getMock();
        $socket->method('send')->willReturnCallback(function (string $payload) use (&$wsSent): void {
            $wsSent[] = $payload;
        });
        $wsSocketProp = new \ReflectionProperty(WS::class, 'socket');
        $wsSocketProp->setAccessible(true);
        $wsSocketProp->setValue($ws, $socket);
    }

    // UDP itself is final; inject a mock React Datagram Buffer so that
    // `$udp->send()` (inherited from Socket) routes into our capture.
    $udp = (new \ReflectionClass(UDP::class))->newInstanceWithoutConstructor();

    $buffer = invokeUdpTransportProtected($test, 'getMockBuilder', [\React\Datagram\Buffer::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send'])
        ->getMock();
    $buffer->method('send')->willReturnCallback(function (string $data) use (&$sentBytes): void {
        $sentBytes[] = $data;
    });

    $bufferProp = new \ReflectionProperty(\React\Datagram\Socket::class, 'buffer');
    $bufferProp->setAccessible(true);
    $bufferProp->setValue($udp, $buffer);

    $udpWsProp = new \ReflectionProperty(UDP::class, 'ws');
    $udpWsProp->setAccessible(true);
    $udpWsProp->setValue($udp, $ws);

    $vcUdpProp = new \ReflectionProperty(VoiceClient::class, 'udp');
    $vcUdpProp->setAccessible(true);
    $vcUdpProp->setValue($vc, $udp);

    return $udp;
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeUdpTransportProtected(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}
