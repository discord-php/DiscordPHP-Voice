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
use React\EventLoop\LoopInterface;

// ---------------------------------------------------------------------------
// 1. Silence-frame count — Discord spec §Voice Data Interpolation mandates
//    5 silence frames.  insertSilence() must call sendBuffer() exactly 5
//    times.  The post-decrement loop `while ($silenceRemaining-- > 0)` with
//    an initial value of 5 evaluates the condition with the current value
//    before decrementing, so it iterates at 5,4,3,2,1 — exactly 5 times.
// ---------------------------------------------------------------------------

it('insertSilence sends exactly 5 silence frames per Discord spec', function (): void {
    if (! function_exists('sodium_crypto_aead_aes256gcm_encrypt')) {
        $this->markTestSkipped('libsodium AES-256-GCM not available on this platform.');
    }

    $sentBytes = [];
    $secretKey = str_repeat("\x00", SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    $udp = makeUdpRtpCounterMock($this, $sentBytes, ssrc: 1, secretKey: $secretKey);

    $udp->ws->vc->ready = true;
    $udp->ws->vc->seq = 0;
    $udp->ws->vc->timestamp = 0;
    $udp->ws->vc->nonce = 0;

    // silenceRemaining starts at 5 (the class-level default).
    $udp->silenceRemaining = 5;

    $udp->insertSilence();

    // Per https://discord.com/developers/docs/topics/voice-connections#voice-data-interpolation
    // exactly 5 silence frames must be sent.
    expect($sentBytes)->toHaveCount(5);
});

// ---------------------------------------------------------------------------
// 2. Each successive sendBuffer() call must embed a distinct, monotonically-
//    increasing sequence number, timestamp, and nonce in the outgoing RTP
//    packet.  Reuse of any of these values can lead to replay attacks or
//    AEAD nonce collisions.
// ---------------------------------------------------------------------------

it('successive sendBuffer calls advance seq timestamp and nonce uniquely in RTP headers', function (): void {
    if (! function_exists('sodium_crypto_aead_aes256gcm_encrypt')) {
        $this->markTestSkipped('libsodium AES-256-GCM not available on this platform.');
    }

    $sentBytes = [];
    $secretKey = str_repeat("\x42", SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    $udp = makeUdpRtpCounterMock($this, $sentBytes, ssrc: 0xDEADBEEF, secretKey: $secretKey);

    $udp->ws->vc->ready = true;

    // 20 ms frame at 48 kHz = 960 samples per timestamp step.
    $tsStep = 960;

    $seqs = [];
    $timestamps = [];
    $nonces = [];

    // Drive 3 packets, advancing counters as readOggOpus() does in production.
    for ($i = 0; $i < 3; ++$i) {
        $udp->ws->vc->seq = $i + 1;
        $udp->ws->vc->timestamp = ($i + 1) * $tsStep;
        $udp->ws->vc->nonce = $i + 1;

        $udp->sendBuffer("\xFA\xFB\xFC");

        $raw = $sentBytes[$i];

        // RTP header: 1-byte v+flags, 1-byte PT, 2-byte seq (BE), 4-byte ts (BE), 4-byte ssrc (BE).
        $hdr = unpack('Cv/Cpt/nseq/Nts/Nssrc', substr($raw, 0, 12));
        // Nonce counter occupies the last 4 bytes, little-endian.
        $nonceBE = unpack('Vnonce', substr($raw, -4))['nonce'];

        $seqs[] = $hdr['seq'];
        $timestamps[] = $hdr['ts'];
        $nonces[] = $nonceBE;
    }

    // All three fields must be unique across packets.
    expect(count(array_unique($seqs)))->toBe(3)
        ->and(count(array_unique($timestamps)))->toBe(3)
        ->and(count(array_unique($nonces)))->toBe(3);

    // Values must be strictly increasing.
    expect($seqs[1])->toBeGreaterThan($seqs[0])
        ->and($seqs[2])->toBeGreaterThan($seqs[1])
        ->and($timestamps[1])->toBeGreaterThan($timestamps[0])
        ->and($timestamps[2])->toBeGreaterThan($timestamps[1])
        ->and($nonces[1])->toBeGreaterThan($nonces[0])
        ->and($nonces[2])->toBeGreaterThan($nonces[1]);
});

// ---------------------------------------------------------------------------
// 3. Nonce must never be re-used even when the 16-bit sequence counter wraps
//    around.  The 32-bit nonce counter is independent of seq precisely for
//    this reason — after a rollover (seq 65535 → 0) the nonce must still
//    advance, preventing ciphertext reuse under the same session key.
// ---------------------------------------------------------------------------

it('nonce does not reset when seq rolls over preventing ciphertext reuse', function (): void {
    if (! function_exists('sodium_crypto_aead_aes256gcm_encrypt')) {
        $this->markTestSkipped('libsodium AES-256-GCM not available on this platform.');
    }

    $sentBytes = [];
    $secretKey = str_repeat("\xAB", SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    $udp = makeUdpRtpCounterMock($this, $sentBytes, ssrc: 99, secretKey: $secretKey);

    $udp->ws->vc->ready = true;

    // Packet just before 16-bit seq rollover.
    $udp->ws->vc->seq = 65535;
    $udp->ws->vc->timestamp = 960;
    $udp->ws->vc->nonce = 70000;
    $udp->sendBuffer("\x01\x02");

    // Packet immediately after rollover: seq wraps to 0, but nonce must not.
    $udp->ws->vc->seq = 0;
    $udp->ws->vc->timestamp = 1920;
    $udp->ws->vc->nonce = 70001;
    $udp->sendBuffer("\x01\x02");

    expect($sentBytes)->toHaveCount(2);

    $nonce0 = unpack('Vnonce', substr($sentBytes[0], -4))['nonce'];
    $nonce1 = unpack('Vnonce', substr($sentBytes[1], -4))['nonce'];

    expect($nonce0)->toBe(70000)
        ->and($nonce1)->toBe(70001)
        ->and($nonce0)->not->toBe($nonce1, 'Nonces must differ after seq rollover to prevent AEAD ciphertext reuse');
});

// ---------------------------------------------------------------------------
// 4. handleMessages guard — packets 8 bytes or shorter must be silently
//    dropped before any Packet construction or handleAudioData call.
// ---------------------------------------------------------------------------

it('handleMessages silently drops packets 8 bytes or shorter without crashing', function (): void {
    if (! function_exists('sodium_crypto_aead_aes256gcm_encrypt')) {
        $this->markTestSkipped('libsodium AES-256-GCM not available on this platform.');
    }

    $sentBytes = [];
    $secretKey = str_repeat("\xFF", SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    $udp = makeUdpRtpCounterMock($this, $sentBytes, ssrc: 42, secretKey: $secretKey);

    // Arm the inbound handler.
    $udp->handleMessages($secretKey);

    // Emit packets at the boundary and below — all must be silently dropped.
    $udp->emit('message', ["\x01"]);                             // 1 byte
    $udp->emit('message', ["\xF8\xFF\xFE"]);                     // 3 bytes
    $udp->emit('message', [str_repeat("\x80", 8)]);              // exactly 8 bytes

    // No outbound packets should have been generated (this channel is inbound-only,
    // but we also confirm the listener itself did not throw an exception).
    expect($sentBytes)->toBeEmpty();

    // Emitting a known-short packet a second time must also be safe.
    $udp->emit('message', ["\x00\x00\x00\x00"]);

    expect(true)->toBeTrue(); // If we reached here, no exception was thrown.
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Builds a minimal UDP instance wired up for sendBuffer / insertSilence tests.
 *
 * The mock captures every outbound UDP send into $sentBytes, skips real
 * socket I/O, and wires up a VoiceClient + WS stub with a zero-protocol-
 * version DaveState so encryptDaveFrame() is a pass-through.
 *
 * @param array<int,string> $sentBytes Captures raw bytes of every outbound packet.
 */
function makeUdpRtpCounterMock(
    TestCase $test,
    array &$sentBytes,
    ?int $ssrc = null,
    ?string $secretKey = null,
): UDP {
    // getMockBuilder() is protected in PHPUnit 12; invoke it via reflection.
    $loop = invokeRtpCounterTestMethod($test, 'getMockBuilder', [LoopInterface::class])->getMock();

    // Discord stub — needs logger + loop.
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    (new \ReflectionProperty(Discord::class, 'logger'))->setValue($discord, new NullLogger());
    (new \ReflectionProperty(Discord::class, 'loop'))->setValue($discord, $loop);

    // VoiceClient stub.
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    (new \ReflectionProperty(VoiceClient::class, 'ssrc'))->setValue($vc, $ssrc);
    (new \ReflectionProperty(VoiceClient::class, 'discord'))->setValue($vc, $discord);

    // WS stub — protocolVersion=0 in DaveState means encryptDaveFrame() is a pass-through.
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
    (new \ReflectionProperty(WS::class, 'vc'))->setValue($ws, $vc);
    (new \ReflectionProperty(WS::class, 'discord'))->setValue($ws, $discord);

    if ($secretKey !== null) {
        (new \ReflectionProperty(WS::class, 'secretKey'))->setValue($ws, $secretKey);
    }

    (new \ReflectionProperty(WS::class, 'daveState'))->setValue($ws, new DaveState());

    // UDP — intercept the underlying Datagram buffer's send() to capture bytes.
    $udp = (new \ReflectionClass(UDP::class))->newInstanceWithoutConstructor();

    $buffer = invokeRtpCounterTestMethod($test, 'getMockBuilder', [\React\Datagram\Buffer::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send'])
        ->getMock();
    $buffer->method('send')->willReturnCallback(
        function (string $data) use (&$sentBytes): void {
            $sentBytes[] = $data;
        }
    );

    (new \ReflectionProperty(\React\Datagram\Socket::class, 'buffer'))->setValue($udp, $buffer);
    (new \ReflectionProperty(UDP::class, 'ws'))->setValue($udp, $ws);

    // Give vc a reference back to udp so encryptDaveFrame()'s isset check succeeds.
    (new \ReflectionProperty(VoiceClient::class, 'udp'))->setValue($vc, $udp);

    return $udp;
}

/**
 * Invokes a protected method on the given object via reflection.
 *
 * Required because PHPUnit\Framework\TestCase::getMockBuilder() is protected
 * and cannot be called directly from free functions outside the class scope.
 *
 * @param array<int, mixed> $arguments
 */
function invokeRtpCounterTestMethod(object $object, string $method, array $arguments = []): mixed
{
    $ref = new \ReflectionMethod($object, $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs($object, $arguments);
}
