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
use Discord\Voice\Processes\OpusFfi;
use Discord\Voice\RecieveStream;
use Discord\Voice\ReceiveStream;
use Discord\Voice\Speaking;
use Discord\Voice\VoiceClient;
use Psr\Log\NullLogger;

// ---------------------------------------------------------------------------
// 1. voiceDecoders initialisation
// ---------------------------------------------------------------------------

it('voiceDecoders is an array (not null) before any audio arrives', function (): void {
    [$vc] = makeVcForRecordExt();

    // Production code never initialises $voiceDecoders in the constructor or
    // in record().  After newInstanceWithoutConstructor() the property is null.
    // count(null) inside createDecoder() would throw a TypeError on the very
    // first audio packet — this test documents the expected (correct) state.
    expect($vc->voiceDecoders)->toBeArray()->toBeEmpty();
});

// ---------------------------------------------------------------------------
// 2. Decoder created once per SSRC, reused afterwards
// ---------------------------------------------------------------------------

it('createDecoder() is called exactly once for a new SSRC and reused on subsequent packets', function (): void {
    $vc = (new \ReflectionMethod(\PHPUnit\Framework\TestCase::class, 'getMockBuilder'))
        ->invoke($this, VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['createDecoder'])
        ->getMock();

    initVcForReceiveExt($vc);
    $vc->record();
    $vc->voiceDecoders = [];
    $vc->receiveStreams = [];

    $ssrc = 1001;
    $col = Collection::for(Speaking::class, 'ssrc');
    $col->pushItem(makeSpeakingExt($ssrc, 'user-1001'));
    setVcSpeakingStatusExt($vc, $col);

    $fakeDecoder = makeFakeDecoderExt(writable: false);

    // Expect createDecoder to be called exactly once even when two packets arrive
    $vc->expects($this->exactly(1))
        ->method('createDecoder')
        ->willReturnCallback(function ($ss) use ($vc, $fakeDecoder): void {
            $vc->voiceDecoders[$ss->ssrc] = $fakeDecoder;
        });

    // First packet: decoder does not exist yet → createDecoder() called
    $vc->handleAudioData(makeReceivePacketExt($ssrc, 'valid-opus-payload-1234'));
    // Second packet: decoder already in voiceDecoders → NOT recreated
    $vc->handleAudioData(makeReceivePacketExt($ssrc, 'valid-opus-payload-5678'));
});

// ---------------------------------------------------------------------------
// 3a. Opus silence frame handled gracefully — no crash, no write
// ---------------------------------------------------------------------------

it('handleAudioData() silently drops the Opus silence frame without throwing', function (): void {
    $vc = (new \ReflectionMethod(\PHPUnit\Framework\TestCase::class, 'getMockBuilder'))
        ->invoke($this, VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['createDecoder'])
        ->getMock();

    initVcForReceiveExt($vc);
    $vc->record();
    $vc->voiceDecoders = [];
    $vc->receiveStreams = [];

    $ssrc = 2001;
    $col = Collection::for(Speaking::class, 'ssrc');
    $col->pushItem(makeSpeakingExt($ssrc, 'user-2001'));
    setVcSpeakingStatusExt($vc, $col);

    $writeCount = 0;
    $fakeDecoder = makeFakeDecoderExtTracking(writable: true, writeCount: $writeCount);

    $vc->method('createDecoder')
        ->willReturnCallback(function ($ss) use ($vc, $fakeDecoder): void {
            $vc->voiceDecoders[$ss->ssrc] = $fakeDecoder;
        });

    // "\xf8\xff\xfe" is the 3-byte Opus comfort-noise/silence frame
    $packet = makeReceivePacketExt($ssrc, "\xf8\xff\xfe");

    expect(fn () => $vc->handleAudioData($packet))->not->toThrow(\Throwable::class);
    expect($writeCount)->toBe(0, 'silence frame must not be forwarded to decoder stdin');
});

// ---------------------------------------------------------------------------
// 3b. Frames shorter than 8 bytes handled gracefully — no crash, no write
// ---------------------------------------------------------------------------

it('handleAudioData() silently drops frames shorter than 8 bytes without throwing', function (): void {
    $vc = (new \ReflectionMethod(\PHPUnit\Framework\TestCase::class, 'getMockBuilder'))
        ->invoke($this, VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['createDecoder'])
        ->getMock();

    initVcForReceiveExt($vc);
    $vc->record();
    $vc->voiceDecoders = [];
    $vc->receiveStreams = [];

    $ssrc = 2002;
    $col = Collection::for(Speaking::class, 'ssrc');
    $col->pushItem(makeSpeakingExt($ssrc, 'user-2002'));
    setVcSpeakingStatusExt($vc, $col);

    $writeCount = 0;
    $fakeDecoder = makeFakeDecoderExtTracking(writable: true, writeCount: $writeCount);

    $vc->method('createDecoder')
        ->willReturnCallback(function ($ss) use ($vc, $fakeDecoder): void {
            $vc->voiceDecoders[$ss->ssrc] = $fakeDecoder;
        });

    // 7 bytes is below the minimum valid Opus frame size (8)
    $packet = makeReceivePacketExt($ssrc, "\x01\x02\x03\x04\x05\x06\x07");

    expect(fn () => $vc->handleAudioData($packet))->not->toThrow(\Throwable::class);
    expect($writeCount)->toBe(0, 'short frame must not be forwarded to decoder stdin');
});

// ---------------------------------------------------------------------------
// 4. OpusFfi unavailable — no fallback write, no crash
// ---------------------------------------------------------------------------

it('when opusdecoder is null (OpusFfi unavailable) valid frames are not written to decoder stdin', function (): void {
    $vc = (new \ReflectionMethod(\PHPUnit\Framework\TestCase::class, 'getMockBuilder'))
        ->invoke($this, VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['createDecoder'])
        ->getMock();

    initVcForReceiveExt($vc);
    $vc->record();
    $vc->voiceDecoders = [];
    $vc->receiveStreams = [];
    $vc->opusdecoder = null; // explicitly no FFI decoder available

    $ssrc = 3001;
    $col = Collection::for(Speaking::class, 'ssrc');
    $col->pushItem(makeSpeakingExt($ssrc, 'user-3001'));
    setVcSpeakingStatusExt($vc, $col);

    $writeCount = 0;
    $fakeDecoder = makeFakeDecoderExtTracking(writable: true, writeCount: $writeCount);

    $vc->method('createDecoder')
        ->willReturnCallback(function ($ss) use ($vc, $fakeDecoder): void {
            $vc->voiceDecoders[$ss->ssrc] = $fakeDecoder;
        });

    // A valid-length Opus frame (> 8 bytes, not silence) that would normally be decoded
    $validOpusFrame = str_repeat("\xAB", 32);
    $packet = makeReceivePacketExt($ssrc, $validOpusFrame);

    expect(fn () => $vc->handleAudioData($packet))->not->toThrow(\Throwable::class);
    // Because opusdecoder is null the decoder branch is skipped entirely —
    // the frame falls through to writeOpus() and decoder stdin is not touched.
    expect($writeCount)->toBe(0, 'without an opusdecoder no data should reach decoder stdin');
});

it('when opusdecoder returns empty PCM the frame is discarded without writing to decoder stdin', function (): void {
    $vc = (new \ReflectionMethod(\PHPUnit\Framework\TestCase::class, 'getMockBuilder'))
        ->invoke($this, VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['createDecoder'])
        ->getMock();

    initVcForReceiveExt($vc);
    $vc->record();
    $vc->voiceDecoders = [];
    $vc->receiveStreams = [];

    // Stub decoder that always returns empty/whitespace PCM
    $stubDecoder = new class implements OpusDecoderInterface {
        public function decode($data, int $channels = 2, int $audioRate = 48000): string
        {
            return '   '; // empty after trim()
        }
    };
    $vc->opusdecoder = $stubDecoder;

    $ssrc = 3002;
    $col = Collection::for(Speaking::class, 'ssrc');
    $col->pushItem(makeSpeakingExt($ssrc, 'user-3002'));
    setVcSpeakingStatusExt($vc, $col);

    $writeCount = 0;
    $fakeDecoder = makeFakeDecoderExtTracking(writable: true, writeCount: $writeCount);

    $vc->method('createDecoder')
        ->willReturnCallback(function ($ss) use ($vc, $fakeDecoder): void {
            $vc->voiceDecoders[$ss->ssrc] = $fakeDecoder;
        });

    $packet = makeReceivePacketExt($ssrc, str_repeat("\xAB", 32));
    $vc->handleAudioData($packet);

    expect($writeCount)->toBe(0, 'empty PCM from opusdecoder must not be forwarded');
});

// ---------------------------------------------------------------------------
// 5. ReceiveStream cleanup via removeDecoder (user disconnects)
// ---------------------------------------------------------------------------

it('removeDecoder() removes the decoder, receive stream and SSRC map entry for the disconnected user', function (): void {
    [$vc] = makeVcForRecordExt();

    $ssrc = 4001;
    $col = Collection::for(Speaking::class, 'ssrc');
    $col->pushItem(makeSpeakingExt($ssrc, 'user-leaving'));
    setVcSpeakingStatusExt($vc, $col);

    $closed = false;
    $fakeDecoder = makeFakeDecoderExtWithClose($closed);

    $vc->voiceDecoders = [$ssrc => $fakeDecoder];
    $vc->receiveStreams = [$ssrc => new ReceiveStream()];

    $ssrcMapProp = new \ReflectionProperty(VoiceClient::class, 'ssrcToUserId');
    $ssrcMapProp->setAccessible(true);
    $ssrcMapProp->setValue($vc, [$ssrc => 'user-leaving']);

    $removeDecoder = new \ReflectionMethod(VoiceClient::class, 'removeDecoder');
    $removeDecoder->setAccessible(true);

    $ss = getVcSpeakingStatusExt($vc)->get('ssrc', $ssrc);
    $removeDecoder->invoke($vc, $ss);

    expect($vc->voiceDecoders)->not->toHaveKey($ssrc)
        ->and($vc->receiveStreams)->not->toHaveKey($ssrc)
        ->and($ssrcMapProp->getValue($vc))->not->toHaveKey($ssrc)
        ->and($closed)->toBeTrue('decoder close() must be called on disconnect');
});

it('removeDecoder() is a no-op when the SSRC has no associated decoder', function (): void {
    [$vc] = makeVcForRecordExt();

    $ssrc = 4002;
    $col = Collection::for(Speaking::class, 'ssrc');
    $col->pushItem(makeSpeakingExt($ssrc, 'user-unknown'));
    setVcSpeakingStatusExt($vc, $col);
    $vc->voiceDecoders = []; // no decoder for this SSRC
    $vc->receiveStreams = [];

    $removeDecoder = new \ReflectionMethod(VoiceClient::class, 'removeDecoder');
    $removeDecoder->setAccessible(true);

    $ss = getVcSpeakingStatusExt($vc)->get('ssrc', $ssrc);
    // Must not throw even though the decoder is absent
    expect(fn () => $removeDecoder->invoke($vc, $ss))->not->toThrow(\Throwable::class);
});

// ---------------------------------------------------------------------------
// 5b. stopRecording() calls close() on every active decoder
// ---------------------------------------------------------------------------

it('stopRecording() calls close() on every active decoder before clearing voiceDecoders', function (): void {
    [$vc] = makeVcForRecordExt();
    $vc->record();

    $closedA = false;
    $closedB = false;

    $fakeDecoderA = makeFakeDecoderExtWithClose($closedA);
    $fakeDecoderB = makeFakeDecoderExtWithClose($closedB);

    $vc->voiceDecoders = [1 => $fakeDecoderA, 2 => $fakeDecoderB];
    $vc->receiveStreams = [];
    setVcSpeakingStatusExt($vc, Collection::for(Speaking::class, 'ssrc'));

    $ssrcMapProp = new \ReflectionProperty(VoiceClient::class, 'ssrcToUserId');
    $ssrcMapProp->setAccessible(true);
    $ssrcMapProp->setValue($vc, []);

    $vc->stopRecording();

    expect($closedA)->toBeTrue('first decoder must be closed')
        ->and($closedB)->toBeTrue('second decoder must be closed')
        ->and($vc->voiceDecoders)->toBeEmpty();
});

// ---------------------------------------------------------------------------
// 6. ReceiveStream pause buffer has a bounded size
// ---------------------------------------------------------------------------

it('ReceiveStream opus pause buffer is capped at 512 frames and does not grow unboundedly', function (): void {
    $stream = new ReceiveStream();
    $stream->pause();

    for ($i = 0; $i < 700; ++$i) {
        $stream->writeOpus("opus-frame-{$i}");
    }

    $opusBufProp = new \ReflectionProperty(RecieveStream::class, 'opusPauseBuffer');
    $opusBufProp->setAccessible(true);

    expect(count($opusBufProp->getValue($stream)))->toBeLessThanOrEqual(512);
});

it('ReceiveStream PCM pause buffer is capped at 512 frames and does not grow unboundedly', function (): void {
    $stream = new ReceiveStream();
    $stream->pause();

    for ($i = 0; $i < 700; ++$i) {
        $stream->writePCM("pcm-frame-{$i}");
    }

    $pcmBufProp = new \ReflectionProperty(RecieveStream::class, 'pcmPauseBuffer');
    $pcmBufProp->setAccessible(true);

    expect(count($pcmBufProp->getValue($stream)))->toBeLessThanOrEqual(512);
});

// ---------------------------------------------------------------------------
// Extra: MAX_DECODERS cap in createDecoder()
// ---------------------------------------------------------------------------

it('createDecoder() refuses to add a new decoder once MAX_DECODERS (25) is reached', function (): void {
    [$vc] = makeVcForRecordExt();
    $vc->record();

    // Pre-fill exactly 25 fake decoders
    $decoders = [];
    for ($i = 0; $i < 25; ++$i) {
        $decoders[$i] = makeFakeDecoderExtSimple();
    }
    $vc->voiceDecoders = $decoders;
    $vc->receiveStreams = [];
    $col = Collection::for(Speaking::class, 'ssrc');
    $col->pushItem(makeSpeakingExt(9999, 'user-overflow'));
    setVcSpeakingStatusExt($vc, $col);

    $createDecoder = new \ReflectionMethod(VoiceClient::class, 'createDecoder');
    $createDecoder->setAccessible(true);

    $ss = getVcSpeakingStatusExt($vc)->get('ssrc', 9999);
    $createDecoder->invoke($vc, $ss);

    // Still 25; the 26th was refused
    expect($vc->voiceDecoders)->toHaveCount(25);
});

// ---------------------------------------------------------------------------
// 7. FFI decoder path: writePCM() triggers channel-pcm; decoder stdin also written
// ---------------------------------------------------------------------------

it('handleAudioData() with FFI decoder emits channel-pcm and writes PCM to decoder stdin', function (): void {
    $vc = (new \ReflectionMethod(\PHPUnit\Framework\TestCase::class, 'getMockBuilder'))
        ->invoke($this, VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['createDecoder'])
        ->getMock();

    initVcForReceiveExt($vc);
    $vc->record();
    $vc->voiceDecoders = [];
    $vc->receiveStreams = [];

    $fakePcm = str_repeat("\x7F\x00", 960);
    $stubDecoder = new class($fakePcm) implements OpusDecoderInterface {
        public function __construct(private string $pcm)
        {
        }

        public function decode($data, int $channels = 2, int $audioRate = 48000): string
        {
            return $this->pcm;
        }
    };
    $vc->opusdecoder = $stubDecoder;

    $ssrc = 6001;
    $col = Collection::for(Speaking::class, 'ssrc');
    $col->pushItem(makeSpeakingExt($ssrc, 'user-6001'));
    setVcSpeakingStatusExt($vc, $col);

    $stdinLog = new \stdClass();
    $stdinLog->writes = [];
    $fakeDecoder = new class($stdinLog) {
        public object $stdin;

        public function __construct(\stdClass $log)
        {
            $this->stdin = new class($log) {
                public function __construct(private \stdClass $log)
                {
                }

                public function isWritable(): bool
                {
                    return true;
                }

                public function write(string $data): bool
                {
                    $this->log->writes[] = $data;

                    return true;
                }
            };
        }
    };

    $vc->expects($this->once())
        ->method('createDecoder')
        ->willReturnCallback(function ($ss) use ($vc, $fakeDecoder): void {
            $vc->voiceDecoders[$ss->ssrc] = $fakeDecoder;
        });

    $pcmEvents = [];
    $vc->on('channel-pcm', function ($data) use (&$pcmEvents): void {
        $pcmEvents[] = $data;
    });

    $packet = makeReceivePacketExt($ssrc, str_repeat("\xAB", 32));
    $vc->handleAudioData($packet);

    expect($pcmEvents)->toBe([$fakePcm], 'channel-pcm must carry decoded PCM bytes')
        ->and($stdinLog->writes)->toBe([$fakePcm], 'decoder stdin must receive the same PCM for Opus re-encoding');
});

// ---------------------------------------------------------------------------
// 7b. Non-FFI else path: writeOpus() triggers channel-opus; decoder stdin NOT written
// ---------------------------------------------------------------------------

it('handleAudioData() without FFI decoder emits channel-opus from the raw Opus frame', function (): void {
    $vc = (new \ReflectionMethod(\PHPUnit\Framework\TestCase::class, 'getMockBuilder'))
        ->invoke($this, VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['createDecoder'])
        ->getMock();

    initVcForReceiveExt($vc);
    $vc->record();
    $vc->voiceDecoders = [];
    $vc->receiveStreams = [];
    $vc->opusdecoder = null;

    $ssrc = 6002;
    $col = Collection::for(Speaking::class, 'ssrc');
    $col->pushItem(makeSpeakingExt($ssrc, 'user-6002'));
    setVcSpeakingStatusExt($vc, $col);

    $writeCount = 0;
    $fakeDecoder = makeFakeDecoderExtTracking(writable: true, writeCount: $writeCount);

    $vc->expects($this->once())
        ->method('createDecoder')
        ->willReturnCallback(function ($ss) use ($vc, $fakeDecoder): void {
            $vc->voiceDecoders[$ss->ssrc] = $fakeDecoder;
        });

    $opusEvents = [];
    $vc->on('channel-opus', function ($data) use (&$opusEvents): void {
        $opusEvents[] = $data;
    });

    $opusFrame = str_repeat("\xCC", 32);
    $packet = makeReceivePacketExt($ssrc, $opusFrame);
    $vc->handleAudioData($packet);

    expect($opusEvents)->toBe([$opusFrame], 'channel-opus must carry the raw Opus frame when no FFI decoder is set')
        ->and($writeCount)->toBe(0, 'decoder stdin must not be written in the non-FFI else path');
});

// ---------------------------------------------------------------------------
// 7c. Per-SSRC OpusFfi decoder isolation
// ---------------------------------------------------------------------------

it('handleAudioData() with OpusFfi decoder creates a distinct per-SSRC ffiDecoder instance', function (): void {
    $vc = (new \ReflectionMethod(\PHPUnit\Framework\TestCase::class, 'getMockBuilder'))
        ->invoke($this, VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['createDecoder'])
        ->getMock();

    initVcForReceiveExt($vc);
    $vc->record();
    $vc->voiceDecoders = [];
    $vc->receiveStreams = [];

    $opusFfi = new class extends OpusFfi {
        public function decode($data, int $channels = 2, int $audioRate = 48000): string
        {
            return str_repeat("\x00", 960 * 2 * 2);
        }
    };
    $vc->opusdecoder = $opusFfi;

    foreach ([7001, 7002] as $ssrc) {
        $col = Collection::for(Speaking::class, 'ssrc');
        $col->pushItem(makeSpeakingExt($ssrc, "user-{$ssrc}"));
        setVcSpeakingStatusExt($vc, $col);

        $fakeDecoder = makeFakeDecoderExt(writable: true);
        $vc->method('createDecoder')
            ->willReturnCallback(function ($ss) use ($vc, $fakeDecoder): void {
                $vc->voiceDecoders[$ss->ssrc] = $fakeDecoder;
            });
        $vc->handleAudioData(makeReceivePacketExt($ssrc, str_repeat("\xAB", 32)));
    }

    $ffiDecodersProp = new \ReflectionProperty(VoiceClient::class, 'ffiDecoders');
    $ffiDecodersProp->setAccessible(true);
    $decoders = $ffiDecodersProp->getValue($vc);

    expect($decoders)->toHaveKey(7001)
        ->toHaveKey(7002);
    expect($decoders[7001])->not->toBe($decoders[7002], 'each SSRC must have its own independent OpusFfi instance');
});

it('handleAudioData() with OpusFfi reuses the same ffiDecoder instance for repeated packets from one SSRC', function (): void {
    $vc = (new \ReflectionMethod(\PHPUnit\Framework\TestCase::class, 'getMockBuilder'))
        ->invoke($this, VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['createDecoder'])
        ->getMock();

    initVcForReceiveExt($vc);
    $vc->record();
    $vc->voiceDecoders = [];
    $vc->receiveStreams = [];

    $opusFfi = new class extends OpusFfi {
        public function decode($data, int $channels = 2, int $audioRate = 48000): string
        {
            return str_repeat("\x00", 960 * 2 * 2);
        }
    };
    $vc->opusdecoder = $opusFfi;

    $ssrc = 8001;
    $col = Collection::for(Speaking::class, 'ssrc');
    $col->pushItem(makeSpeakingExt($ssrc, 'user-8001'));
    setVcSpeakingStatusExt($vc, $col);

    $fakeDecoder = makeFakeDecoderExt(writable: true);
    $vc->method('createDecoder')
        ->willReturnCallback(function ($ss) use ($vc, $fakeDecoder): void {
            $vc->voiceDecoders[$ss->ssrc] = $fakeDecoder;
        });

    $vc->handleAudioData(makeReceivePacketExt($ssrc, str_repeat("\xAB", 32)));
    $vc->handleAudioData(makeReceivePacketExt($ssrc, str_repeat("\xCD", 32)));

    $ffiDecodersProp = new \ReflectionProperty(VoiceClient::class, 'ffiDecoders');
    $ffiDecodersProp->setAccessible(true);
    $decoders = $ffiDecodersProp->getValue($vc);

    expect($decoders)->toHaveCount(1);
    expect($decoders)->toHaveKey($ssrc);
});

// ---------------------------------------------------------------------------
// 8. record() auto-initialisation of OpusFfi
// ---------------------------------------------------------------------------

it('record() does not override an already-configured opusdecoder', function (): void {
    [$vc] = makeVcForRecordExt();

    $stubDecoder = new class implements OpusDecoderInterface {
        public function decode($data, int $channels = 2, int $audioRate = 48000): string
        {
            return '';
        }
    };
    $vc->opusdecoder = $stubDecoder;

    $vc->record();

    expect($vc->opusdecoder)->toBe($stubDecoder, 'record() must not replace a pre-configured opusdecoder');
});

it('record() auto-initialises OpusFfi when opusdecoder is null and libopus is available', function (): void {
    if (! OpusFfi::isAvailable()) {
        $this->markTestSkipped('Requires libopus to be available.');
    }

    [$vc] = makeVcForRecordExt();
    $vc->opusdecoder = null;

    $vc->record();

    expect($vc->opusdecoder)->toBeInstanceOf(OpusFfi::class);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeVcForRecordExt(): array
{
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    initVcForReceiveExt($vc);

    return [$vc];
}

function setVcSpeakingStatusExt(VoiceClient $vc, mixed $value): void
{
    $prop = new \ReflectionProperty(VoiceClient::class, 'speakingStatus');
    $prop->setAccessible(true);
    $prop->setValue($vc, $value);
}

function getVcSpeakingStatusExt(VoiceClient $vc): mixed
{
    $prop = new \ReflectionProperty(VoiceClient::class, 'speakingStatus');
    $prop->setAccessible(true);

    return $prop->getValue($vc);
}

function initVcForReceiveExt(VoiceClient $vc): void
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

function makeSpeakingExt(int $ssrc, string $userId): Speaking
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

function makeReceivePacketExt(int $ssrc, string $decryptedAudio): Packet
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

/**
 * Returns a fake decoder with a stdin whose isWritable() returns $writable.
 * Nothing tracks writes — use makeFakeDecoderExtTracking() when you need that.
 */
function makeFakeDecoderExt(bool $writable): object
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

/**
 * Returns a fake decoder whose stdin tracks how many times write() is called.
 * Pass $writeCount by reference to observe writes from outside.
 */
function makeFakeDecoderExtTracking(bool $writable, int &$writeCount): object
{
    return new class($writable, $writeCount) {
        public $stdin;

        public function __construct(bool $w, int &$count)
        {
            $this->stdin = new class($w, $count) {
                public function __construct(private bool $w, private int &$count)
                {
                }

                public function isWritable(): bool
                {
                    return $this->w;
                }

                public function write(string $data): bool
                {
                    ++$this->count;

                    return true;
                }
            };
        }
    };
}

/**
 * Returns a fake decoder that tracks whether close() was called.
 * Pass $closed by reference to observe from outside.
 */
function makeFakeDecoderExtWithClose(bool &$closed): object
{
    return new class($closed) {
        public $stdin;
        private bool $isClosed;

        public function __construct(bool &$c)
        {
            $this->isClosed = &$c;
            $this->stdin = new class {
                public function isWritable(): bool
                {
                    return false;
                }
            };
        }

        public function isRunning(): bool
        {
            return false;
        }

        public function close(): void
        {
            $this->isClosed = true;
        }
    };
}

/** Returns a minimal fake decoder with no special tracking. */
function makeFakeDecoderExtSimple(): object
{
    return new class {
        public $stdin;

        public function __construct()
        {
            $this->stdin = new class {
                public function isWritable(): bool
                {
                    return false;
                }
            };
        }

        public function isRunning(): bool
        {
            return false;
        }

        public function close(): void
        {
        }
    };
}
