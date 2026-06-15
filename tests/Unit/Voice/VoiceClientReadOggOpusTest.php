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
use Discord\Voice\Helpers\Buffer as HelperBuffer;
use Discord\Voice\OggStream;
use Discord\Voice\VoiceClient;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\Promise\Deferred;

use function React\Async\await;

it('readOggOpus inserts silence and reschedules itself when paused', function (): void {
    $silenceCalls = 0;
    $sendCalls = 0;

    $udp = makeUdpStubForOpus($this, $sendCalls, $silenceCalls);
    $vc = makeVcForReadOpus($udp, paused: true);

    $ogg = (new \ReflectionClass(OggStream::class))->newInstanceWithoutConstructor();
    $loops = 0;
    $deferred = new Deferred();

    invokeReadOggOpus($vc, $deferred, $ogg, $loops);

    Loop::get()->addTimer(0.06, fn () => Loop::get()->stop());
    Loop::get()->run();

    expect($silenceCalls)->toBeGreaterThanOrEqual(1)
        ->and($sendCalls)->toBe(0);
});

it('readOggOpus resolves the deferred and resets state on Ogg EOF', function (): void {
    $buffer = new HelperBuffer();
    $buffer->write(buildOggOpusHeadersForOpus());
    $buffer->close();

    $ogg = await(OggStream::fromBuffer($buffer));

    $sendCalls = 0;
    $silenceCalls = 0;
    $udp = makeUdpStubForOpus($this, $sendCalls, $silenceCalls);

    $vc = makeVcForReadOpus($udp, paused: false);

    $deferred = new Deferred();
    $resolved = false;
    $deferred->promise()->then(function () use (&$resolved): void {
        $resolved = true;
    });

    $loops = 0;
    invokeReadOggOpus($vc, $deferred, $ogg, $loops);

    Loop::get()->addTimer(0.05, fn () => Loop::get()->stop());
    Loop::get()->run();

    expect($resolved)->toBeTrue();
});

it('readOggOpus dispatches packets via UDP::sendBuffer for non-paused playback', function (): void {
    $packetA = "\x40\x01opus-A";
    $packetB = "\x40\x01opus-B";

    $buffer = new HelperBuffer();
    $buffer->write(
        buildOggOpusHeadersForOpus()
        .buildOggOpusPageForOpus([strlen($packetA)], $packetA)
        .buildOggOpusPageForOpus([strlen($packetB)], $packetB)
    );
    $buffer->close();

    $ogg = await(OggStream::fromBuffer($buffer));

    $sentBuffers = [];
    $silenceCalls = 0;
    $sendCalls = 0;
    $udp = (new \ReflectionMethod(\PHPUnit\Framework\TestCase::class, 'getMockBuilder'))
        ->invoke($this, UDP::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['sendBuffer', 'insertSilence'])
        ->getMock();
    $udp->method('sendBuffer')->willReturnCallback(function (string $data) use (&$sentBuffers): void {
        $sentBuffers[] = $data;
    });
    $udp->method('insertSilence')->willReturnCallback(function () use (&$silenceCalls): void {
        $silenceCalls++;
    });

    $vc = makeVcForReadOpus($udp, paused: false);
    // Set startTime in the past so timers fire on the first loop tick.
    $st = new \ReflectionProperty(VoiceClient::class, 'startTime');
    $st->setAccessible(true);
    $st->setValue($vc, microtime(true) - 1.0);

    $deferred = new Deferred();
    $resolved = false;
    $deferred->promise()->then(function () use (&$resolved): void {
        $resolved = true;
    });

    $loops = 0;
    invokeReadOggOpus($vc, $deferred, $ogg, $loops);

    Loop::get()->addTimer(0.1, fn () => Loop::get()->stop());
    Loop::get()->run();

    expect($sentBuffers)->toContain($packetA, $packetB)
        ->and($resolved)->toBeTrue();
});

// Helpers

function makeVcForReadOpus(UDP $udp, bool $paused): VoiceClient
{
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();

    $discord = new class extends Discord {
        public function __construct()
        {
        }
        public function getLogger(): \Psr\Log\LoggerInterface
        {
            return new NullLogger();
        }
        public function getLoop(): \React\EventLoop\LoopInterface
        {
            return Loop::get();
        }
    };

    $vc->discord = $discord;
    $vc->udp = $udp;

    $sp = new \ReflectionProperty(VoiceClient::class, 'paused');
    $sp->setAccessible(true);
    $sp->setValue($vc, $paused);

    $st = new \ReflectionProperty(VoiceClient::class, 'startTime');
    $st->setAccessible(true);
    $st->setValue($vc, microtime(true));

    $rt = new \ReflectionProperty(VoiceClient::class, 'readOpusTimer');
    $rt->setAccessible(true);
    $rt->setValue($vc, null);

    return $vc;
}

function makeUdpStubForOpus(\PHPUnit\Framework\TestCase $tc, int &$sendCalls, int &$silenceCalls): UDP
{
    $mock = (new \ReflectionMethod(\PHPUnit\Framework\TestCase::class, 'getMockBuilder'))
        ->invoke($tc, UDP::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['sendBuffer', 'insertSilence'])
        ->getMock();

    $mock->method('sendBuffer')->willReturnCallback(function () use (&$sendCalls): void {
        $sendCalls++;
    });
    $mock->method('insertSilence')->willReturnCallback(function () use (&$silenceCalls): void {
        $silenceCalls++;
    });

    return $mock;
}

function invokeReadOggOpus(VoiceClient $vc, Deferred $deferred, OggStream &$ogg, int &$loops): void
{
    $r = new \ReflectionMethod(VoiceClient::class, 'readOggOpus');
    $r->setAccessible(true);
    $r->invokeArgs($vc, [$deferred, &$ogg, &$loops]);
}

function buildOggOpusHeadersForOpus(): string
{
    $header = 'OpusHead'.pack('CCvVvC', 1, 2, 312, 48000, 0, 0);
    $tags = 'OpusTags'.pack('V', 0).pack('V', 0);

    return buildOggOpusPageForOpus([strlen($header)], $header).buildOggOpusPageForOpus([strlen($tags)], $tags);
}

function buildOggOpusPageForOpus(array $segments, string $segmentData): string
{
    $page = 'OggS'
        .pack('CCPVVVC', 0, 0, 0, 1, 1, 0, count($segments))
        .pack('C*', ...$segments)
        .$segmentData;

    $crc = 0;
    for ($i = 0; $i < strlen($page); $i++) {
        $crc ^= (ord($page[$i]) << 24);
        for ($j = 0; $j < 8; $j++) {
            $crc = ($crc & 0x80000000) ? (($crc << 1) ^ 0x04C11DB7) & 0xFFFFFFFF : ($crc << 1) & 0xFFFFFFFF;
        }
    }

    return substr($page, 0, 22).pack('V', $crc).substr($page, 26);
}
