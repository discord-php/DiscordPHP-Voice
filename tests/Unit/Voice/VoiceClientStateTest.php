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
use Discord\Voice\ReceiveStream;
use Discord\Voice\Speaking;
use Discord\Voice\VoiceClient;
use Psr\Log\NullLogger;

it('getReceiveStream() returns the stream for a known ssrc directly', function (): void {
    $vc = makeVcForState();
    $stream = new ReceiveStream();
    $vc->receiveStreams[42] = $stream;

    expect($vc->getReceiveStream(42))->toBe($stream);
});

it('getReceiveStream() resolves user_id → ssrc → stream', function (): void {
    $vc = makeVcForState();
    $coll = Collection::for(Speaking::class, 'ssrc');
    $coll->pushItem(makeSpeakingForState(99, 'user-9'));
    setVcCollection($vc, $coll);

    $stream = new ReceiveStream();
    $vc->receiveStreams[99] = $stream;

    expect($vc->getReceiveStream('user-9'))->toBe($stream);
});

it('getReceiveStream() returns null when nothing matches', function (): void {
    $vc = makeVcForState();
    setVcCollection($vc, Collection::for(Speaking::class, 'ssrc'));

    expect($vc->getReceiveStream('nobody'))->toBeNull();
});

it('getRecieveStream() (deprecated misspelling) delegates to getReceiveStream', function (): void {
    $vc = makeVcForState();
    $stream = new ReceiveStream();
    $vc->receiveStreams[7] = $stream;

    expect($vc->getRecieveStream(7))->toBe($stream);
});

it('handleVoiceStateUpdate() ignores users with no speakingStatus entry', function (): void {
    $vc = makeVcForState();
    setVcCollection($vc, Collection::for(Speaking::class, 'ssrc'));

    $vc->handleVoiceStateUpdate((object) ['user_id' => 'unknown', 'channel_id' => '999']);

    expect($vc->voiceDecoders)->toBe([]);
});

it('setMuteDeaf() throws when the client is not ready', function (): void {
    $vc = makeVcForState();

    $ready = new \ReflectionProperty(VoiceClient::class, 'ready');
    $ready->setAccessible(true);
    $ready->setValue($vc, false);

    expect(fn () => $vc->setMuteDeaf(true, false))
        ->toThrow(\RuntimeException::class, 'must be ready');
});

it('setBitrate() rejects values below 8000 bps', function (): void {
    $vc = makeVcForState();

    expect(fn () => $vc->setBitrate(7999))->toThrow(\DomainException::class);
});

it('setBitrate() rejects values above 384000 bps', function (): void {
    $vc = makeVcForState();

    expect(fn () => $vc->setBitrate(384001))->toThrow(\DomainException::class);
});

it('setBitrate() accepts a value within the legal range', function (): void {
    $vc = makeVcForState();
    $vc->setBitrate(96000);

    $prop = new \ReflectionProperty(VoiceClient::class, 'bitrate');
    $prop->setAccessible(true);
    expect($prop->getValue($vc))->toBe(96000);
});

it('setVolume() rejects values outside 0-100 percent range', function (): void {
    $vc = makeVcForState();

    expect(fn () => $vc->setVolume(-1))->toThrow(\DomainException::class)
        ->and(fn () => $vc->setVolume(101))->toThrow(\DomainException::class);
});

it('setVolume() throws while audio is playing', function (): void {
    $vc = makeVcForState();

    $sp = new \ReflectionProperty(VoiceClient::class, 'speaking');
    $sp->setAccessible(true);
    $sp->setValue($vc, 1);

    expect(fn () => $vc->setVolume(50))->toThrow(\RuntimeException::class, 'while playing');
});

it('setVolume() persists the value when not playing', function (): void {
    $vc = makeVcForState();
    $vc->setVolume(75);

    $prop = new \ReflectionProperty(VoiceClient::class, 'volume');
    $prop->setAccessible(true);
    expect($prop->getValue($vc))->toBe(75);
});

it('setAudioApplication() rejects unknown application names', function (): void {
    $vc = makeVcForState();

    expect(fn () => $vc->setAudioApplication('bogus'))->toThrow(\DomainException::class);
});

it('setAudioApplication() throws while audio is playing', function (): void {
    $vc = makeVcForState();

    $sp = new \ReflectionProperty(VoiceClient::class, 'speaking');
    $sp->setAccessible(true);
    $sp->setValue($vc, 1);

    expect(fn () => $vc->setAudioApplication('voip'))->toThrow(\RuntimeException::class, 'while playing');
});

it('setAudioApplication() accepts each documented audio application', function (): void {
    foreach (['voip', 'audio', 'lowdelay'] as $app) {
        $vc = makeVcForState();
        $vc->setAudioApplication($app);

        $prop = new \ReflectionProperty(VoiceClient::class, 'audioApplication');
        $prop->setAccessible(true);
        expect($prop->getValue($vc))->toBe($app);
    }
});

it('close() throws when not connected', function (): void {
    $vc = makeVcForState();

    $ready = new \ReflectionProperty(VoiceClient::class, 'ready');
    $ready->setAccessible(true);
    $ready->setValue($vc, false);

    expect(fn () => $vc->close())->toThrow(\RuntimeException::class, 'not connected');
});

it('pause() throws when audio is not playing', function (): void {
    $vc = makeVcForState();

    expect(fn () => $vc->pause())->toThrow(\RuntimeException::class, 'must be playing');
});

it('unpause() throws when audio is not playing', function (): void {
    $vc = makeVcForState();

    expect(fn () => $vc->unpause())->toThrow(\RuntimeException::class, 'must be playing');
});

it('unpause() throws when not paused', function (): void {
    $vc = makeVcForState();

    $sp = new \ReflectionProperty(VoiceClient::class, 'speaking');
    $sp->setAccessible(true);
    $sp->setValue($vc, 1);

    expect(fn () => $vc->unpause())->toThrow(\RuntimeException::class, 'already playing');
});

it('stop() throws when audio is not playing', function (): void {
    $vc = makeVcForState();

    expect(fn () => $vc->stop())->toThrow(\RuntimeException::class, 'must be playing');
});

it('playPcmFile() rejects when path does not exist', function (): void {
    $vc = makeVcForState();

    $promise = $vc->playPcmFile('/nonexistent/path/to/missing.pcm');

    $caught = null;
    $promise->then(null, function ($e) use (&$caught) {
        $caught = $e;
    });

    expect($caught)->toBeInstanceOf(\Discord\Exceptions\FileNotFoundException::class);
});

it('isReady() reflects the ready property', function (): void {
    $vc = makeVcForState();

    $ready = new \ReflectionProperty(VoiceClient::class, 'ready');
    $ready->setAccessible(true);

    $ready->setValue($vc, false);
    expect($vc->isReady())->toBeFalse();

    $ready->setValue($vc, true);
    expect($vc->isReady())->toBeTrue();
});

// Helpers

function makeVcForState(): VoiceClient
{
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();

    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $loggerProp = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProp->setAccessible(true);
    $loggerProp->setValue($discord, new NullLogger());
    $vc->discord = $discord;

    $vc->voiceDecoders = [];
    $vc->receiveStreams = [];

    return $vc;
}

function setVcCollection(VoiceClient $vc, $value): void
{
    $prop = new \ReflectionProperty(VoiceClient::class, 'speakingStatus');
    $prop->setAccessible(true);
    $prop->setValue($vc, $value);
}

function makeSpeakingForState(int $ssrc, string $userId, int $speaking = 0): Speaking
{
    $s = (new \ReflectionClass(Speaking::class))->newInstanceWithoutConstructor();
    $attrs = new \ReflectionProperty(Speaking::class, 'attributes');
    $attrs->setAccessible(true);
    $attrs->setValue($s, [
        'ssrc' => $ssrc,
        'user_id' => $userId,
        'speaking' => $speaking,
        'delay' => 0,
    ]);

    return $s;
}
