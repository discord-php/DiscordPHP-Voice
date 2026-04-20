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
use Discord\Voice\Client\WS;
use Discord\Voice\Dave\Runtime;
use Discord\Voice\VoiceClient;
use Psr\Log\NullLogger;
use React\EventLoop\TimerInterface;

afterEach(function (): void {
    Runtime::reset();
});

// ──────────────────────────────────────────────────────────────
// VULN-05: handleVoiceServerChange double-close
// ──────────────────────────────────────────────────────────────

it('handleVoiceServerChange calls ws->close exactly once via close()', function (): void {
    // Build a partial mock: let handleVoiceServerChange run for real, but stub
    // close() and pause() so that internal I/O teardown does not fail.
    $vc = $this->getMockBuilder(VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['pause', 'close'])
        ->getMock();

    // Mock the raw Ratchet WebSocket that VoiceClient::$ws points at.
    $mockWs = $this->getMockBuilder(\Ratchet\Client\WebSocket::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['close'])
        ->getMock();

    // The mocked close() simulates what the real close() does: closes the socket once.
    $vc->method('close')->willReturnCallback(function () use ($vc): void {
        $vc->ws->close();
    });

    $mockWs->expects($this->once())->method('close');
    $vc->ws = $mockWs;

    $discord = makeDiscordForFixesTest();
    $vc->discord = $discord;

    $dataProp = new \ReflectionProperty(VoiceClient::class, 'data');
    $dataProp->setAccessible(true);
    $dataProp->setValue($vc, ['token' => 'tok', 'endpoint' => 'fake.voice.endpoint']);

    try {
        $vc->handleVoiceServerChange(['token' => 'tok', 'endpoint' => 'fake.voice.endpoint']);
    } catch (\Throwable) {
        // WS::make() will throw (LibDaveNotFoundException or connection error) in tests.
        // That is expected; the close() assertion was already exercised before that point.
    }
});

// ──────────────────────────────────────────────────────────────
// VULN-19: monitorProcessTimer leaked on multi-user sessions
// ──────────────────────────────────────────────────────────────

it('monitorProcessExit cancels the previous timer before creating a new one', function (): void {
    $cancelledTimers = [];

    /** @var TimerInterface $firstTimer */
    $firstTimer = $this->getMockBuilder(TimerInterface::class)->getMock();
    /** @var TimerInterface $secondTimer */
    $secondTimer = $this->getMockBuilder(TimerInterface::class)->getMock();

    $timerCallCount = 0;

    $mockLoop = $this->getMockBuilder(\React\EventLoop\LoopInterface::class)->getMock();
    $mockLoop->method('addPeriodicTimer')->willReturnCallback(
        function () use ($firstTimer, $secondTimer, &$timerCallCount): TimerInterface {
            return $timerCallCount++ === 0 ? $firstTimer : $secondTimer;
        }
    );
    $mockLoop->method('cancelTimer')->willReturnCallback(
        function (TimerInterface $timer) use (&$cancelledTimers): void {
            $cancelledTimers[] = $timer;
        }
    );

    $discord = makeDiscordForFixesTest($mockLoop);

    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->discord = $discord;

    // Inject null for monitorProcessTimer (as if no timer exists yet).
    $timerProp = new \ReflectionProperty(VoiceClient::class, 'monitorProcessTimer');
    $timerProp->setAccessible(true);
    $timerProp->setValue($vc, null);

    $method = new \ReflectionMethod(VoiceClient::class, 'monitorProcessExit');
    $method->setAccessible(true);

    $process = $this->getMockBuilder(\React\ChildProcess\Process::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['isRunning'])
        ->getMock();
    $process->method('isRunning')->willReturn(true);

    $ss = (object) ['ssrc' => 1, 'user_id' => 'u1'];

    // First call — no previous timer, nothing should be cancelled.
    $method->invokeArgs($vc, [$process, $ss]);
    expect($cancelledTimers)->toBeEmpty();
    expect($timerProp->getValue($vc))->toBe($firstTimer);

    // Second call — the first timer must be cancelled before the new one is created.
    $method->invokeArgs($vc, [$process, $ss]);
    expect($cancelledTimers)->toHaveCount(1);
    expect($cancelledTimers[0])->toBe($firstTimer);
    expect($timerProp->getValue($vc))->toBe($secondTimer);
});

// ──────────────────────────────────────────────────────────────
// VULN-07/08: handleAudioData dead string path removed
// ──────────────────────────────────────────────────────────────

it('handleAudioData signature only accepts Packet, not a plain string', function (): void {
    $method = new \ReflectionMethod(VoiceClient::class, 'handleAudioData');

    $params = $method->getParameters();
    expect($params)->toHaveCount(1);

    $type = $params[0]->getType();
    expect($type)->not->toBeNull();
    expect($type->getName())->toBe(Packet::class);
});

it('handleAudioData with a Packet returns early when shouldRecord is false', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();

    $shouldRecordProp = new \ReflectionProperty(VoiceClient::class, 'shouldRecord');
    $shouldRecordProp->setAccessible(true);
    $shouldRecordProp->setValue($vc, false);

    $packet = (new \ReflectionClass(Packet::class))->newInstanceWithoutConstructor();

    // Should not throw and should return without touching speakingStatus.
    $vc->handleAudioData($packet);

    // If we got here without a TypeError or null dereference, the string path is gone.
    expect(true)->toBeTrue();
});

// Helpers

function makeDiscordForFixesTest(?\React\EventLoop\LoopInterface $loop = null): Discord
{
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();

    $loggerProp = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProp->setAccessible(true);
    $loggerProp->setValue($discord, new NullLogger());

    if ($loop !== null) {
        $loopProp = new \ReflectionProperty(Discord::class, 'loop');
        $loopProp->setAccessible(true);
        $loopProp->setValue($discord, $loop);
    }

    return $discord;
}
