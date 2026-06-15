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
use Discord\Voice\ReceiveStream;
use Discord\Voice\Speaking;
use Discord\Voice\VoiceClient;
use Psr\Log\NullLogger;

it('record() flips shouldRecord to true', function (): void {
    [$vc] = makeVcForRecord();

    $shouldRecord = new \ReflectionProperty(VoiceClient::class, 'shouldRecord');
    $shouldRecord->setAccessible(true);

    expect($shouldRecord->getValue($vc))->toBeFalse();

    $vc->record();

    expect($shouldRecord->getValue($vc))->toBeTrue();
});

it('record() throws if already recording', function (): void {
    [$vc] = makeVcForRecord();

    $vc->record();

    expect(fn () => $vc->record())
        ->toThrow(\RuntimeException::class, 'Already recording audio.');
});

it('stopRecording() throws if not recording', function (): void {
    [$vc] = makeVcForRecord();

    expect(fn () => $vc->stopRecording())
        ->toThrow(\RuntimeException::class, 'Not recording audio.');
});

it('stopRecording() clears recording state', function (): void {
    [$vc] = makeVcForRecord();

    $vc->voiceDecoders = [];
    $vc->receiveStreams = [];
    $speakingStatusProp = new \ReflectionProperty(VoiceClient::class, 'speakingStatus');
    $speakingStatusProp->setAccessible(true);
    $speakingStatusProp->setValue($vc, Collection::for(Speaking::class, 'ssrc'));
    $ssrcMap = new \ReflectionProperty(VoiceClient::class, 'ssrcToUserId');
    $ssrcMap->setAccessible(true);
    $ssrcMap->setValue($vc, [123 => 'user-1']);

    $vc->record();
    $vc->stopRecording();

    $shouldRecord = new \ReflectionProperty(VoiceClient::class, 'shouldRecord');
    $shouldRecord->setAccessible(true);

    expect($shouldRecord->getValue($vc))->toBeFalse()
        ->and($vc->voiceDecoders)->toBe([])
        ->and($vc->receiveStreams)->toBe([])
        ->and($ssrcMap->getValue($vc))->toBe([]);
});

it('handleAudioData() ignores packets when not recording', function (): void {
    [$vc] = makeVcForRecord();

    $emitted = [];
    $vc->on('raw', function () use (&$emitted): void {
        $emitted[] = 'raw';
    });

    $packet = makeReceivePacket(42, 'opus-bytes');

    $vc->handleAudioData($packet);

    expect($emitted)->toBe([]);
});

it('handleAudioData() ignores packets with unknown SSRC', function (): void {
    [$vc] = makeVcForRecord();
    $vc->record();

    setVcSpeakingStatus($vc, Collection::for(Speaking::class, 'ssrc'));

    $emitted = [];
    $vc->on('raw', function () use (&$emitted): void {
        $emitted[] = 'raw';
    });

    $packet = makeReceivePacket(9999, 'opus-bytes');

    $vc->handleAudioData($packet);

    expect($emitted)->toBe([]);
});

it('handleAudioData() ignores packets with empty decrypted audio', function (): void {
    [$vc] = makeVcForRecord();
    $vc->record();

    $ssrc = 1234;
    $col = Collection::for(Speaking::class, 'ssrc');
    $col->pushItem(makeSpeaking($ssrc, 'user-1'));
    setVcSpeakingStatus($vc, $col);

    $emitted = [];
    $vc->on('raw', function () use (&$emitted): void {
        $emitted[] = 'raw';
    });

    $packet = makeReceivePacket($ssrc, '');

    $vc->handleAudioData($packet);

    expect($emitted)->toBe([]);
});

it('handleAudioData() lazily creates a ReceiveStream and wires channel-opus + channel-pcm events', function (): void {
    $vc = (new \ReflectionMethod(\PHPUnit\Framework\TestCase::class, 'getMockBuilder'))
        ->invoke($this, VoiceClient::class)
        ->disableOriginalConstructor()
        ->onlyMethods(['createDecoder'])
        ->getMock();

    initVcForReceive($vc);
    $vc->record();

    $ssrc = 5555;
    $col = Collection::for(Speaking::class, 'ssrc');
    $col->pushItem(makeSpeaking($ssrc, 'user-xyz'));
    setVcSpeakingStatus($vc, $col);

    // Stub createDecoder so it does NOT spawn ffmpeg, but installs a fake
    // decoder whose stdin reports as not writable, forcing handleAudioData
    // to bail before attempting to write opus data.
    $fakeStdin = new class {
        public function isWritable(): bool
        {
            return false;
        }
    };
    $fakeDecoder = new class($fakeStdin) {
        public function __construct(public $stdin)
        {
        }
    };

    $vc->expects($this->once())
        ->method('createDecoder')
        ->willReturnCallback(function ($ss) use ($vc, $fakeDecoder): void {
            $vc->voiceDecoders[$ss->ssrc] = $fakeDecoder;
        });

    $rawEvents = [];
    $opusEvents = [];
    $pcmEvents = [];
    $vc->on('raw', function ($data) use (&$rawEvents): void {
        $rawEvents[] = $data;
    });
    $vc->on('channel-opus', function ($data) use (&$opusEvents): void {
        $opusEvents[] = $data;
    });
    $vc->on('channel-pcm', function ($data) use (&$pcmEvents): void {
        $pcmEvents[] = $data;
    });

    $packet = makeReceivePacket($ssrc, 'encoded-opus-payload');
    $vc->handleAudioData($packet);

    expect($rawEvents)->toBe(['encoded-opus-payload'])
        ->and($vc->receiveStreams)->toHaveKey($ssrc)
        ->and($vc->receiveStreams[$ssrc])->toBeInstanceOf(ReceiveStream::class);

    // Drive the receive stream directly to confirm the channel-* listeners
    // were attached during the lazy-creation branch.
    $vc->receiveStreams[$ssrc]->writeOpus('opus-out');
    $vc->receiveStreams[$ssrc]->writePCM('pcm-out');

    expect($opusEvents)->toBe(['opus-out'])
        ->and($pcmEvents)->toBe(['pcm-out']);
});

it('handleAudioData() reuses an existing ReceiveStream and does not recreate the decoder', function (): void {
    [$vc] = makeVcForRecord();
    $vc->record();

    $ssrc = 7777;
    $col = Collection::for(Speaking::class, 'ssrc');
    $col->pushItem(makeSpeaking($ssrc, 'user-existing'));
    setVcSpeakingStatus($vc, $col);

    $existingStream = new ReceiveStream();
    $vc->receiveStreams = [$ssrc => $existingStream];

    $fakeStdin = new class {
        public function isWritable(): bool
        {
            return false;
        }
    };
    $fakeDecoder = new class($fakeStdin) {
        public function __construct(public $stdin)
        {
        }
    };
    $vc->voiceDecoders = [$ssrc => $fakeDecoder];

    $opusEvents = [];
    $vc->on('channel-opus', function ($data) use (&$opusEvents): void {
        $opusEvents[] = $data;
    });

    $packet = makeReceivePacket($ssrc, 'frame');
    $vc->handleAudioData($packet);

    expect($vc->receiveStreams[$ssrc])->toBe($existingStream);

    // The pre-existing stream had no listeners attached by handleAudioData,
    // so writing opus/pcm should not emit channel-opus.
    $existingStream->writeOpus('out');
    expect($opusEvents)->toBe([]);
});

// Helpers

function makeVcForRecord(): array
{
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    initVcForReceive($vc);

    return [$vc];
}

function setVcSpeakingStatus(VoiceClient $vc, mixed $value): void
{
    $prop = new \ReflectionProperty(VoiceClient::class, 'speakingStatus');
    $prop->setAccessible(true);
    $prop->setValue($vc, $value);
}

function initVcForReceive(VoiceClient $vc): void
{
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $loggerProp = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProp->setAccessible(true);
    $loggerProp->setValue($discord, new NullLogger());

    $vc->discord = $discord;

    // Initialise typed-nullable properties accessed by reset()/handleAudioData()
    // so they are not in an uninitialised state.
    $readOpusTimer = new \ReflectionProperty(VoiceClient::class, 'readOpusTimer');
    $readOpusTimer->setAccessible(true);
    $readOpusTimer->setValue($vc, null);

    $startTime = new \ReflectionProperty(VoiceClient::class, 'startTime');
    $startTime->setAccessible(true);
    $startTime->setValue($vc, 0);
}

function makeSpeaking(int $ssrc, string $userId): Speaking
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

function makeReceivePacket(int $ssrc, string $decryptedAudio): Packet
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
