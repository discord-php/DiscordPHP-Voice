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
use Discord\Voice\VoiceClient;
use Psr\Log\NullLogger;

it('playOggStream rejects when client is not ready', function (): void {
    $vc = makeVcForPlay(ready: false);

    $err = catchPromiseError($vc->playOggStream(fopen('php://memory', 'rb')));
    expect($err)->toBeInstanceOf(\RuntimeException::class)
        ->and($err->getMessage())->toContain('not ready');
});

it('playOggStream rejects when already speaking', function (): void {
    $vc = makeVcForPlay(ready: true, speaking: true);

    $err = catchPromiseError($vc->playOggStream(fopen('php://memory', 'rb')));
    expect($err)->toBeInstanceOf(\RuntimeException::class)
        ->and($err->getMessage())->toContain('already playing');
});

it('playOggStream rejects an invalid stream argument', function (): void {
    $vc = makeVcForPlay(ready: true);

    $err = catchPromiseError($vc->playOggStream('not-a-stream'));
    expect($err)->toBeInstanceOf(\InvalidArgumentException::class);
});

it('playDCAStream rejects when client is not ready', function (): void {
    $vc = makeVcForPlay(ready: false);

    $err = catchPromiseError($vc->playDCAStream(fopen('php://memory', 'rb')));
    expect($err)->toBeInstanceOf(\Exception::class)
        ->and($err->getMessage())->toContain('not ready');
});

it('playDCAStream rejects when already speaking', function (): void {
    $vc = makeVcForPlay(ready: true, speaking: true);

    $err = catchPromiseError($vc->playDCAStream(fopen('php://memory', 'rb')));
    expect($err)->toBeInstanceOf(\Exception::class)
        ->and($err->getMessage())->toContain('already playing');
});

it('playDCAStream rejects an invalid stream argument', function (): void {
    $vc = makeVcForPlay(ready: true);

    $err = catchPromiseError($vc->playDCAStream('not-a-stream'));
    expect($err)->toBeInstanceOf(\Exception::class)
        ->and($err->getMessage())->toContain('not an instance of resource');
});

it('playFile rejects when client is not ready', function (): void {
    $vc = makeVcForPlay(ready: false);

    $err = catchPromiseError($vc->playFile(__FILE__));
    expect($err)->toBeInstanceOf(\Discord\Voice\Exceptions\ClientNotReadyException::class);
});

it('playFile rejects with FileNotFound when path does not exist and is not a URL', function (): void {
    $vc = makeVcForPlay(ready: true);

    $err = catchPromiseError($vc->playFile('/no/such/file.mp3'));
    expect($err)->toBeInstanceOf(\Discord\Exceptions\FileNotFoundException::class);
});

it('playFile rejects already-playing audio', function (): void {
    $vc = makeVcForPlay(ready: true, speaking: true);

    $err = catchPromiseError($vc->playFile(__FILE__));
    expect($err)->toBeInstanceOf(\Discord\Voice\Exceptions\Channels\AudioAlreadyPlayingException::class);
});

it('playFile rejects URL with disallowed scheme (SSRF guard)', function (): void {
    $vc = makeVcForPlay(ready: true);

    $err = catchPromiseError($vc->playFile('ftp://example.com/audio.mp3'));
    expect($err)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($err->getMessage())->toContain('not allowed');
});

it('playFile rejects URL pointing to localhost (SSRF guard)', function (): void {
    $vc = makeVcForPlay(ready: true);

    $err = catchPromiseError($vc->playFile('https://localhost/audio.mp3'));
    expect($err)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($err->getMessage())->toContain('private or reserved');
});

it('playFile rejects URL pointing to a private IP (SSRF guard)', function (): void {
    $vc = makeVcForPlay(ready: true);

    $err = catchPromiseError($vc->playFile('https://192.168.1.1/audio.mp3'));
    expect($err)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($err->getMessage())->toContain('private or reserved');
});

it('playRawStream rejects when client is not ready', function (): void {
    $vc = makeVcForPlay(ready: false);

    $err = catchPromiseError($vc->playRawStream(fopen('php://memory', 'rb')));
    expect($err)->toBeInstanceOf(\RuntimeException::class)
        ->and($err->getMessage())->toContain('not ready');
});

it('playPcmFile rejects when path does not exist', function (): void {
    $vc = makeVcForPlay(ready: true);

    $err = catchPromiseError($vc->playPcmFile('/nonexistent/path/missing.pcm'));
    expect($err)->toBeInstanceOf(\Discord\Exceptions\FileNotFoundException::class);
});

// Helpers

function makeVcForPlay(bool $ready = false, bool $speaking = false): VoiceClient
{
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();

    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $loggerProp = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProp->setAccessible(true);
    $loggerProp->setValue($discord, new NullLogger());
    $vc->discord = $discord;

    $rp = new \ReflectionProperty(VoiceClient::class, 'ready');
    $rp->setAccessible(true);
    $rp->setValue($vc, $ready);

    $sp = new \ReflectionProperty(VoiceClient::class, 'speaking');
    $sp->setAccessible(true);
    $sp->setValue($vc, $speaking ? 1 : 0);

    return $vc;
}

function catchPromiseError(\React\Promise\PromiseInterface $promise): ?\Throwable
{
    $caught = null;
    $promise->then(null, function (\Throwable $e) use (&$caught): void {
        $caught = $e;
    });

    return $caught;
}
