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

namespace Discord\Tests\Unit\Voice\Recording;

use Discord\Discord;
use Discord\Helpers\Collection;
use Discord\Voice\Client\Packet;
use Discord\Voice\Processes\OpusDecoderInterface;
use Discord\Voice\Recording\RecordingFormat;
use Discord\Voice\Recording\WavWriter;
use Discord\Voice\Speaking;
use Discord\Voice\VoiceClient;
use Psr\Log\NullLogger;

// ---------------------------------------------------------------------------
// Detect integration availability once
// ---------------------------------------------------------------------------

$hasFormatParam = (function (): bool {
    $ref = new \ReflectionMethod(VoiceClient::class, 'record');
    $params = $ref->getParameters();

    return count($params) >= 1 && $params[0]->getName() === 'format';
})();

// ---------------------------------------------------------------------------
// 1. record() with no args still works (backward compat)
// ---------------------------------------------------------------------------

it('record() with no args still works (backward compat)', function (): void {
    $vc = makeVcForRecordingFormat();

    $vc->record();

    $prop = new \ReflectionProperty(VoiceClient::class, 'shouldRecord');
    $prop->setAccessible(true);
    expect($prop->getValue($vc))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 2. record(WAV, null) throws InvalidArgumentException
// ---------------------------------------------------------------------------

it('record(RecordingFormat::WAV, null) throws InvalidArgumentException when outputPath is null', function () use ($hasFormatParam): void {
    if (! $hasFormatParam) {
        $this->markTestSkipped('VoiceClient::record() integration not yet present');
    }

    $vc = makeVcForRecordingFormat();

    expect(fn () => $vc->record(RecordingFormat::WAV, null))->toThrow(\InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// 3. record(RecordingFormat::PCM) does not require an outputPath callback
// ---------------------------------------------------------------------------

it('record(RecordingFormat::PCM) does not require an outputPath callback', function () use ($hasFormatParam): void {
    if (! $hasFormatParam) {
        $this->markTestSkipped('VoiceClient::record() integration not yet present');
    }

    $vc = makeVcForRecordingFormat();

    expect(fn () => $vc->record(RecordingFormat::PCM))->not->toThrow(\Throwable::class);
});

// ---------------------------------------------------------------------------
// 4. WavWriter is opened per SSRC when WAV format is active
// ---------------------------------------------------------------------------

it('WavWriter is opened per SSRC when WAV format is active', function () use ($hasFormatParam): void {
    if (! $hasFormatParam) {
        $this->markTestSkipped('VoiceClient::record() integration not yet present');
    }

    $ssrc = 100;
    $userId = 'user-100';
    $wavPath = sys_get_temp_dir().'/'.$userId.'-'.getmypid().'.wav';

    $vc = (new \ReflectionMethod(\PHPUnit\Framework\TestCase::class, 'getMockBuilder'))
        ->invoke($this, VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['createDecoder'])
        ->getMock();

    initVcForRecordingFormat($vc);

    // Install a stub opusdecoder that returns real-looking PCM (non-empty, non-whitespace).
    $fakePcm = str_repeat("\x7F\x01", 960); // 1920 bytes of non-whitespace PCM
    $vc->opusdecoder = new class($fakePcm) implements OpusDecoderInterface {
        public function __construct(private string $pcm)
        {
        }

        public function decode($data, int $channels = 2, int $audioRate = 48000): string
        {
            return $this->pcm;
        }
    };

    // Stub createDecoder to install a fake process with writable stdin.
    $fakeDecoder = makeFakeDecoderForRecordingFormat(writable: true);
    $vc->method('createDecoder')
        ->willReturnCallback(function ($ss) use ($vc, $fakeDecoder): void {
            $vc->voiceDecoders[$ss->ssrc] = $fakeDecoder;
        });

    $vc->voiceDecoders = [];
    $vc->receiveStreams = [];

    // Set up speaking status.
    $col = Collection::for(Speaking::class, 'ssrc');
    $col->pushItem(makeSpeakingForRecordingFormat($ssrc, $userId));
    setVcSpeakingStatusForRecordingFormat($vc, $col);
    setSsrcToUserIdForRecordingFormat($vc, [$ssrc => $userId]);

    // Activate WAV recording.
    $vc->record(RecordingFormat::WAV, fn (string $uid) => sys_get_temp_dir().'/'.$uid.'-'.getmypid().'.wav');

    // Send one valid audio packet (> 8 bytes, not silence).
    $packet = makeReceivePacketForRecordingFormat($ssrc, str_repeat("\xAB", 32));
    $vc->handleAudioData($packet);

    // Assert recordingWriters[ssrc] is a WavWriter.
    $writersProp = new \ReflectionProperty(VoiceClient::class, 'recordingWriters');
    $writersProp->setAccessible(true);
    $writers = $writersProp->getValue($vc);

    expect($writers)->toHaveKey($ssrc)
        ->and($writers[$ssrc])->toBeInstanceOf(WavWriter::class);

    // Assert the WAV file was created and has more than just the header.
    expect(file_exists($wavPath))->toBeTrue()
        ->and(filesize($wavPath))->toBeGreaterThan(44);

    unlink($wavPath);
});

// ---------------------------------------------------------------------------
// 5. stopRecording() calls finalize() on all open WavWriters
// ---------------------------------------------------------------------------

it('stopRecording() calls finalize() on all open WavWriters', function () use ($hasFormatParam): void {
    if (! $hasFormatParam) {
        $this->markTestSkipped('VoiceClient::record() integration not yet present');
    }

    $vc = makeVcForRecordingFormat();

    $finalized = false;
    $mockWriter = new class($finalized) {
        private bool $wasCalled = false;

        public function __construct(private bool &$flag)
        {
        }

        public function finalize(): void
        {
            $this->flag = true;
            $this->wasCalled = true;
        }

        public function getPath(): string
        {
            return '/fake/path.wav';
        }
    };

    // Inject mock writer and set shouldRecord = true.
    $writersProp = new \ReflectionProperty(VoiceClient::class, 'recordingWriters');
    $writersProp->setAccessible(true);
    $writersProp->setValue($vc, [42 => $mockWriter]);

    $shouldRecordProp = new \ReflectionProperty(VoiceClient::class, 'shouldRecord');
    $shouldRecordProp->setAccessible(true);
    $shouldRecordProp->setValue($vc, true);

    $vc->stopRecording();

    expect($finalized)->toBeTrue('finalize() must be called on injected WavWriter');
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeVcForRecordingFormat(): VoiceClient
{
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    initVcForRecordingFormat($vc);

    return $vc;
}

function initVcForRecordingFormat(VoiceClient $vc): void
{
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $loggerProp = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProp->setAccessible(true);
    $loggerProp->setValue($discord, new NullLogger());

    $vc->discord = $discord;

    $readOpusTimer = new \ReflectionProperty(VoiceClient::class, 'readOpusTimer');
    $readOpusTimer->setAccessible(true);
    $readOpusTimer->setValue($vc, null);

    $startTime = new \ReflectionProperty(VoiceClient::class, 'startTime');
    $startTime->setAccessible(true);
    $startTime->setValue($vc, 0);
}

function setVcSpeakingStatusForRecordingFormat(VoiceClient $vc, mixed $value): void
{
    $prop = new \ReflectionProperty(VoiceClient::class, 'speakingStatus');
    $prop->setAccessible(true);
    $prop->setValue($vc, $value);
}

function setSsrcToUserIdForRecordingFormat(VoiceClient $vc, array $map): void
{
    $prop = new \ReflectionProperty(VoiceClient::class, 'ssrcToUserId');
    $prop->setAccessible(true);
    $prop->setValue($vc, $map);
}

function makeSpeakingForRecordingFormat(int $ssrc, string $userId): Speaking
{
    $speaking = (new \ReflectionClass(Speaking::class))->newInstanceWithoutConstructor();

    $attrs = new \ReflectionProperty(Speaking::class, 'attributes');
    $attrs->setAccessible(true);
    $attrs->setValue($speaking, [
        'ssrc' => $ssrc,
        'user_id' => $userId,
        'speaking' => 1,
        'delay' => 0,
    ]);

    return $speaking;
}

function makeReceivePacketForRecordingFormat(int $ssrc, string $decryptedAudio): Packet
{
    $packet = (new \ReflectionClass(Packet::class))->newInstanceWithoutConstructor();

    $ssrcProp = new \ReflectionProperty(Packet::class, 'ssrc');
    $ssrcProp->setAccessible(true);
    $ssrcProp->setValue($packet, $ssrc);

    $tsProp = new \ReflectionProperty(Packet::class, 'timestamp');
    $tsProp->setAccessible(true);
    $tsProp->setValue($packet, 0);

    $packet->decryptedAudio = $decryptedAudio;

    return $packet;
}

function makeFakeDecoderForRecordingFormat(bool $writable): object
{
    return new class($writable) {
        public $stdin;

        public function __construct(bool $w)
        {
            $this->stdin = new class($w) {
                public function __construct(private bool $w)
                {
                }

                public function isWritable(): bool
                {
                    return $this->w;
                }

                public function write(string $data): bool
                {
                    return true;
                }
            };
        }
    };
}
