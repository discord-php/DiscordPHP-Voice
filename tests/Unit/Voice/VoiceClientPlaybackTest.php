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

use Discord\Exceptions\FileNotFoundException;
use Discord\Voice\Client as VoiceClientAlias;
use Discord\Voice\Client\UDP;
use Discord\Voice\Client\WS;
use Discord\Voice\Exceptions\Channels\AudioAlreadyPlayingException;
use Discord\Voice\Exceptions\ClientNotReadyException;
use Discord\Voice\VoiceClient;
use React\Promise\PromiseInterface;

// ──────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────

function attachUdpForPlaybackTest(VoiceClient $vc, \Ratchet\Client\WebSocket $mockSocket): UDP
{
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();

    // Wire WS->vc so UDP::refreshSilenceFrames / sendBuffer can reach it.
    $vcProp = new \ReflectionProperty(WS::class, 'vc');
    $vcProp->setAccessible(true);
    $vcProp->setValue($ws, $vc);

    $socketProp = new \ReflectionProperty(WS::class, 'socket');
    $socketProp->setAccessible(true);
    $socketProp->setValue($ws, $mockSocket);

    $udp = (new \ReflectionClass(UDP::class))->newInstanceWithoutConstructor();
    $wsProp = new \ReflectionProperty(UDP::class, 'ws');
    $wsProp->setAccessible(true);
    $wsProp->setValue($udp, $ws);

    // Prevent insertSilence from looping into sendBuffer (which needs full RTP/Packet setup).
    $udp->silenceRemaining = 0;

    $vc->udp = $udp;

    return $udp;
}

function captureRejection(PromiseInterface $promise): ?\Throwable
{
    $caught = null;
    $promise->then(null, function (\Throwable $e) use (&$caught): void {
        $caught = $e;
    });

    return $caught;
}

// ──────────────────────────────────────────────────────────────
// pause()
// ──────────────────────────────────────────────────────────────

it('pause throws when not speaking', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->speaking = VoiceClient::NOT_SPEAKING;

    expect(fn () => $vc->pause())
        ->toThrow(\RuntimeException::class, 'Audio must be playing to pause it.');
});

it('pause throws when already paused', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->speaking = VoiceClient::MICROPHONE;
    $vc->paused = true;

    expect(fn () => $vc->pause())
        ->toThrow(\RuntimeException::class, 'Audio is already paused.');
});

it('pause sets paused=true and refreshes silence frames', function (): void {
    $vc = (new \ReflectionClass(VoiceClientAlias::class))->newInstanceWithoutConstructor();
    $vc->speaking = VoiceClient::MICROPHONE;
    $vc->paused = false;

    $mockSocket = $this->getMockBuilder(\Ratchet\Client\WebSocket::class)
        ->disableOriginalConstructor()->onlyMethods(['send'])->getMock();
    $udp = attachUdpForPlaybackTest($vc, $mockSocket);

    // Pre-condition: refreshSilenceFrames keys off vc->paused, so set silenceRemaining
    // to something other than 5 to observe the refresh effect.
    $udp->silenceRemaining = 0;

    $vc->pause();

    expect($vc->paused)->toBeTrue();
    expect($udp->silenceRemaining)->toBe(5);
});

// ──────────────────────────────────────────────────────────────
// unpause()
// ──────────────────────────────────────────────────────────────

it('unpause throws when not speaking', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->speaking = VoiceClient::NOT_SPEAKING;

    expect(fn () => $vc->unpause())
        ->toThrow(\RuntimeException::class, 'Audio must be playing to unpause it.');
});

it('unpause throws when not paused', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->speaking = VoiceClient::MICROPHONE;
    $vc->paused = false;

    expect(fn () => $vc->unpause())
        ->toThrow(\RuntimeException::class, 'Audio is already playing.');
});

it('unpause clears paused flag and refreshes timestamp', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->speaking = VoiceClient::MICROPHONE;
    $vc->paused = true;
    $vc->timestamp = 0;

    $vc->unpause();

    expect($vc->paused)->toBeFalse();
    expect($vc->timestamp)->toBeGreaterThan(0);
});

// ──────────────────────────────────────────────────────────────
// stop()
// ──────────────────────────────────────────────────────────────

it('stop throws when not speaking', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->speaking = VoiceClient::NOT_SPEAKING;

    expect(fn () => $vc->stop())
        ->toThrow(\RuntimeException::class, 'Audio must be playing to stop it.');
});

it('stop drains state, inserts silence and resets speaking', function (): void {
    $vc = (new \ReflectionClass(VoiceClientAlias::class))->newInstanceWithoutConstructor();
    $vc->ready = true;
    $vc->speaking = VoiceClient::MICROPHONE;
    $vc->paused = true;
    $vc->ssrc = 42;

    // reset() cancels readOpusTimer if set; leave it null.
    $readOpusProp = new \ReflectionProperty(VoiceClient::class, 'readOpusTimer');
    $readOpusProp->setAccessible(true);
    $readOpusProp->setValue($vc, null);

    $sendCallCount = 0;
    $mockSocket = $this->getMockBuilder(\Ratchet\Client\WebSocket::class)
        ->disableOriginalConstructor()->onlyMethods(['send'])->getMock();
    $mockSocket->method('send')->willReturnCallback(function () use (&$sendCallCount): void {
        $sendCallCount++;
    });
    attachUdpForPlaybackTest($vc, $mockSocket);

    $vc->stop();

    expect($vc->paused)->toBeFalse();
    // reset() flips speaking to NOT_SPEAKING via setSpeaking(), which sends one VOICE_SPEAKING op.
    expect($sendCallCount)->toBe(1);
});

// ──────────────────────────────────────────────────────────────
// setVolume()
// ──────────────────────────────────────────────────────────────

it('setVolume throws DomainException for out-of-range values', function (int $vol): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->speaking = VoiceClient::NOT_SPEAKING;

    expect(fn () => $vc->setVolume($vol))->toThrow(\DomainException::class);
})->with([-1, 101, 200]);

it('setVolume throws when speaking', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->speaking = VoiceClient::MICROPHONE;

    expect(fn () => $vc->setVolume(50))
        ->toThrow(\RuntimeException::class, 'Cannot change volume while playing.');
});

it('setVolume stores the value when not speaking and within range', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->speaking = VoiceClient::NOT_SPEAKING;

    $volumeProp = new \ReflectionProperty(VoiceClient::class, 'volume');
    $volumeProp->setAccessible(true);
    $volumeProp->setValue($vc, 100);

    $vc->setVolume(75);

    expect($volumeProp->getValue($vc))->toBe(75);
});

// ──────────────────────────────────────────────────────────────
// setAudioApplication()
// ──────────────────────────────────────────────────────────────

it('setAudioApplication throws DomainException for unknown application', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->speaking = VoiceClient::NOT_SPEAKING;

    expect(fn () => $vc->setAudioApplication('bogus'))->toThrow(\DomainException::class);
});

it('setAudioApplication throws when speaking', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->speaking = VoiceClient::MICROPHONE;

    expect(fn () => $vc->setAudioApplication('audio'))
        ->toThrow(\RuntimeException::class, 'Cannot change audio application while playing.');
});

it('setAudioApplication accepts each legal value', function (string $app): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->speaking = VoiceClient::NOT_SPEAKING;

    $appProp = new \ReflectionProperty(VoiceClient::class, 'audioApplication');
    $appProp->setAccessible(true);
    $appProp->setValue($vc, 'audio');

    $vc->setAudioApplication($app);

    expect($appProp->getValue($vc))->toBe($app);
})->with(['voip', 'audio', 'lowdelay']);

// ──────────────────────────────────────────────────────────────
// playFile()
// ──────────────────────────────────────────────────────────────

it('playFile rejects with FileNotFoundException for a missing file', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->ready = true;
    $vc->speaking = VoiceClient::NOT_SPEAKING;

    $rejection = captureRejection($vc->playFile('/no/such/file.mp3'));

    expect($rejection)->toBeInstanceOf(FileNotFoundException::class);
});

it('playFile rejects with ClientNotReadyException when not ready', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->ready = false;
    $vc->speaking = VoiceClient::NOT_SPEAKING;

    // Use an existing file so the FileNotFound guard does not fire first.
    $rejection = captureRejection($vc->playFile(__FILE__));

    expect($rejection)->toBeInstanceOf(ClientNotReadyException::class);
});

it('playFile rejects with AudioAlreadyPlayingException when already speaking', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->ready = true;
    $vc->speaking = VoiceClient::MICROPHONE;

    $rejection = captureRejection($vc->playFile(__FILE__));

    expect($rejection)->toBeInstanceOf(AudioAlreadyPlayingException::class);
});

it('playFile rejects URLs with disallowed schemes', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->ready = true;
    $vc->speaking = VoiceClient::NOT_SPEAKING;

    $rejection = captureRejection($vc->playFile('file:///etc/passwd'));

    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class);
    expect($rejection->getMessage())->toContain("scheme 'file' is not allowed");
});

// ──────────────────────────────────────────────────────────────
// playRawStream()
// ──────────────────────────────────────────────────────────────

it('playRawStream rejects when not ready', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->ready = false;
    $vc->speaking = VoiceClient::NOT_SPEAKING;

    $rejection = captureRejection($vc->playRawStream(fopen('php://memory', 'r+')));

    expect($rejection)->toBeInstanceOf(\RuntimeException::class);
    expect($rejection->getMessage())->toBe('Voice Client is not ready.');
});

it('playRawStream rejects when already speaking', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->ready = true;
    $vc->speaking = VoiceClient::MICROPHONE;

    $rejection = captureRejection($vc->playRawStream(fopen('php://memory', 'r+')));

    expect($rejection)->toBeInstanceOf(\RuntimeException::class);
    expect($rejection->getMessage())->toBe('Audio already playing.');
});

it('playRawStream rejects when stream is not a resource or React Stream', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->ready = true;
    $vc->speaking = VoiceClient::NOT_SPEAKING;

    $rejection = captureRejection($vc->playRawStream('not-a-stream'));

    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class);
});

// ──────────────────────────────────────────────────────────────
// playOggStream()
// ──────────────────────────────────────────────────────────────

it('playOggStream rejects when not ready', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->ready = false;
    $vc->speaking = VoiceClient::NOT_SPEAKING;

    $rejection = captureRejection($vc->playOggStream(fopen('php://memory', 'r+')));

    expect($rejection)->toBeInstanceOf(\RuntimeException::class);
    expect($rejection->getMessage())->toBe('Voice client is not ready yet.');
});

it('playOggStream rejects when already speaking', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->ready = true;
    $vc->speaking = VoiceClient::MICROPHONE;

    $rejection = captureRejection($vc->playOggStream(fopen('php://memory', 'r+')));

    expect($rejection)->toBeInstanceOf(\RuntimeException::class);
    expect($rejection->getMessage())->toBe('Audio already playing.');
});
