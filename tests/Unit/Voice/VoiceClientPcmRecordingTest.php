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
use Discord\Helpers\Collection;
use Discord\Voice\Client\Packet;
use Discord\Voice\Processes\OpusDecoderInterface;
use Discord\Voice\Recording\RecordingFormat;
use Discord\Voice\Speaking;
use Discord\Voice\VoiceClient;
use Psr\Log\NullLogger;

it('handleAudioData() warns and returns when ssrc has no speaking-status entry', function (): void {
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    initPcmRecVc($vc);
    setPcmRecCollection($vc, Collection::for(Speaking::class, 'ssrc'));
    $vc->voiceDecoders = [];
    $vc->receiveStreams = [];
    $vc->record();

    // No matching ssrc → speakingStatus->get() returns null → first guard triggers, then unknown SSRC branch.
    $packet = makePcmRecPacket(99999, str_repeat("\xAA", 32));
    $vc->handleAudioData($packet);

    // Nothing should be created — voiceDecoders + receiveStreams stay empty.
    expect($vc->voiceDecoders)->toBe([])
        ->and($vc->receiveStreams)->toBe([]);
});

it('record(RecordingFormat::PCM, callable) wires per-user PCM file handles on first audio frame', function (): void {
    $tmpDir = sys_get_temp_dir().'/voice-pcm-rec-'.uniqid();

    $vc = (new \ReflectionMethod(\PHPUnit\Framework\TestCase::class, 'getMockBuilder'))
        ->invoke($this, VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['createDecoder'])
        ->getMock();

    initPcmRecVc($vc);

    $stub = new class implements OpusDecoderInterface {
        public function decode($data, int $channels = 2, int $audioRate = 48000): string
        {
            return str_repeat("\x10\x00", 8);
        }
    };
    $vc->opusdecoder = $stub;

    $vc->record(RecordingFormat::PCM, fn (string $userId): string => $tmpDir."/{$userId}.pcm");

    $vc->voiceDecoders = [];
    $vc->receiveStreams = [];

    $col = Collection::for(Speaking::class, 'ssrc');
    $col->pushItem(makePcmRecSpeaking(123, 'user-A'));
    setPcmRecCollection($vc, $col);

    // Wire ssrc→userId map so the writer path uses 'user-A' from the map.
    setPcmRecSsrcMap($vc, [123 => 'user-A']);

    $fakeDecoder = makePcmRecFakeDecoder();
    $vc->method('createDecoder')->willReturnCallback(function ($ss) use ($vc, $fakeDecoder): void {
        $vc->voiceDecoders[$ss->ssrc] = $fakeDecoder;
    });

    $vc->handleAudioData(makePcmRecPacket(123, str_repeat("\xAB", 8)));

    $handlesProp = new \ReflectionProperty(VoiceClient::class, 'recordingPcmHandles');
    $handlesProp->setAccessible(true);
    $handles = $handlesProp->getValue($vc);

    try {
        expect($handles)->toHaveKey(123)
            ->and(file_exists($tmpDir.'/user-A.pcm'))->toBeTrue($tmpDir.' contents: '.implode(',', glob($tmpDir.'/*') ?: []));
    } finally {
        // stopRecording() exercises the PCM-handle teardown path.
        $vc->stopRecording();
        @unlink($tmpDir.'/user-A.pcm');
        @rmdir($tmpDir);
    }
});

it('record(RecordingFormat::PCM, callable) creates the output directory when missing', function (): void {
    $tmpDir = sys_get_temp_dir().'/voice-pcm-mkdir-'.uniqid().'/nested';

    $vc = (new \ReflectionMethod(\PHPUnit\Framework\TestCase::class, 'getMockBuilder'))
        ->invoke($this, VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['createDecoder'])
        ->getMock();

    initPcmRecVc($vc);

    $stub = new class implements OpusDecoderInterface {
        public function decode($data, int $channels = 2, int $audioRate = 48000): string
        {
            return "\x00\x00";
        }
    };
    $vc->opusdecoder = $stub;

    $vc->record(RecordingFormat::PCM, fn (string $userId): string => $tmpDir."/u-{$userId}.pcm");

    $vc->voiceDecoders = [];
    $vc->receiveStreams = [];

    $col = Collection::for(Speaking::class, 'ssrc');
    $col->pushItem(makePcmRecSpeaking(456, 'user-B'));
    setPcmRecCollection($vc, $col);
    setPcmRecSsrcMap($vc, [456 => 'user-B']);

    $fakeDecoder = makePcmRecFakeDecoder();
    $vc->method('createDecoder')->willReturnCallback(function ($ss) use ($vc, $fakeDecoder): void {
        $vc->voiceDecoders[$ss->ssrc] = $fakeDecoder;
    });

    try {
        $vc->handleAudioData(makePcmRecPacket(456, str_repeat("\xCD", 8)));
        expect(is_dir($tmpDir))->toBeTrue();
    } finally {
        $vc->stopRecording();
        @unlink($tmpDir.'/u-user-B.pcm');
        @rmdir($tmpDir);
        @rmdir(dirname($tmpDir));
    }
});

// Helpers

function initPcmRecVc($vc): void
{
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $loggerProp = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProp->setAccessible(true);
    $loggerProp->setValue($discord, new NullLogger());
    $vc->discord = $discord;

    $rt = new \ReflectionProperty(VoiceClient::class, 'readOpusTimer');
    $rt->setAccessible(true);
    $rt->setValue($vc, null);

    $st = new \ReflectionProperty(VoiceClient::class, 'startTime');
    $st->setAccessible(true);
    $st->setValue($vc, 0);
}

function setPcmRecSsrcMap($vc, array $map): void
{
    $p = new \ReflectionProperty(VoiceClient::class, 'ssrcToUserId');
    $p->setAccessible(true);
    $p->setValue($vc, $map);
}

function setPcmRecCollection($vc, $value): void
{
    $prop = new \ReflectionProperty(VoiceClient::class, 'speakingStatus');
    $prop->setAccessible(true);
    $prop->setValue($vc, $value);
}

function makePcmRecSpeaking(int $ssrc, string $userId): Speaking
{
    $s = (new \ReflectionClass(Speaking::class))->newInstanceWithoutConstructor();
    $a = new \ReflectionProperty(Speaking::class, 'attributes');
    $a->setAccessible(true);
    $a->setValue($s, ['ssrc' => $ssrc, 'user_id' => $userId, 'speaking' => 1, 'delay' => 0]);

    return $s;
}

function makePcmRecPacket(int $ssrc, string $audio): Packet
{
    $p = (new \ReflectionClass(Packet::class))->newInstanceWithoutConstructor();
    $sp = new \ReflectionProperty(Packet::class, 'ssrc');
    $sp->setAccessible(true);
    $sp->setValue($p, $ssrc);
    $tp = new \ReflectionProperty(Packet::class, 'timestamp');
    $tp->setAccessible(true);
    $tp->setValue($p, 0);
    $p->decryptedAudio = $audio;

    return $p;
}

function makePcmRecFakeDecoder(): object
{
    return new class {
        public object $stdin;
        public function __construct()
        {
            $this->stdin = new class {
                public function isWritable(): bool
                {
                    return true;
                }
                public function write(string $data): bool
                {
                    return true;
                }
                public function close(): void
                {
                }
            };
        }
        public function close(): void
        {
        }
        public function isRunning(): bool
        {
            return false;
        }
        public function terminate(int $signal): void
        {
        }
    };
}
