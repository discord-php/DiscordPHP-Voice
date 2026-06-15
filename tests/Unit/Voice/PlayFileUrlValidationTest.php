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

use Discord\Voice\VoiceClient;

// IPv4 loopback
it('rejects IPv4 loopback address', function () {
    $rejection = capturePlayFileRejection(makeReadyVoiceClient()->playFile('https://127.0.0.1/'));
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('private or reserved IP');
});

// IPv4 private class A
it('rejects IPv4 private class A address', function () {
    $rejection = capturePlayFileRejection(makeReadyVoiceClient()->playFile('https://10.0.0.1/'));
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('private or reserved IP');
});

// IPv4 private class B
it('rejects IPv4 private class B address', function () {
    $rejection = capturePlayFileRejection(makeReadyVoiceClient()->playFile('https://192.168.1.1/'));
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('private or reserved IP');
});

// IPv4 link-local
it('rejects IPv4 link-local address', function () {
    $rejection = capturePlayFileRejection(makeReadyVoiceClient()->playFile('https://169.254.0.1/'));
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('private or reserved IP');
});

// IPv6 loopback
it('rejects IPv6 loopback address', function () {
    $rejection = capturePlayFileRejection(makeReadyVoiceClient()->playFile('https://[::1]/'));
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('private or reserved IP');
});

// IPv6 link-local
it('rejects IPv6 link-local address', function () {
    $rejection = capturePlayFileRejection(makeReadyVoiceClient()->playFile('https://[fe80::1]/'));
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('private or reserved IP');
});

// IPv6 ULA
it('rejects IPv6 ULA address', function () {
    $rejection = capturePlayFileRejection(makeReadyVoiceClient()->playFile('https://[fc00::1]/'));
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('private or reserved IP');
});

// Public IPv4 — validation passes (ffmpeg may fail later, that's fine)
it('does not reject for public IPv4 address', function () {
    $invalidArgRejected = false;
    try {
        makeReadyVoiceClient()->playFile('https://8.8.8.8/')->then(null, function ($e) use (&$invalidArgRejected) {
            if ($e instanceof \InvalidArgumentException) {
                $invalidArgRejected = true;
            }
        });
    } catch (\InvalidArgumentException $e) {
        $invalidArgRejected = true;
    } catch (\Throwable) {
        // Errors from uninitialised properties (udp, etc.) are expected in test context
    }
    expect($invalidArgRejected)->toBeFalse();
});

// Valid hostname — validation passes
it('does not reject for a valid HTTPS hostname', function () {
    $invalidArgRejected = false;
    try {
        makeReadyVoiceClient()->playFile('https://example.com/audio.ogg')->then(null, function ($e) use (&$invalidArgRejected) {
            if ($e instanceof \InvalidArgumentException) {
                $invalidArgRejected = true;
            }
        });
    } catch (\InvalidArgumentException $e) {
        $invalidArgRejected = true;
    } catch (\Throwable) {
        // Errors from uninitialised properties (udp, etc.) are expected in test context
    }
    expect($invalidArgRejected)->toBeFalse();
});

// HTTP URL — rejected by scheme check
it('rejects http:// scheme', function () {
    $rejection = capturePlayFileRejection(makeReadyVoiceClient()->playFile('http://127.0.0.1/'));
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('not allowed');
});

// Local file path — rejected as file-not-found (not treated as URL)
it('rejects non-existent local file path', function () {
    $rejection = capturePlayFileRejection(makeReadyVoiceClient()->playFile('/nonexistent/local/file.ogg'));
    expect($rejection)->toBeInstanceOf(\Discord\Exceptions\FileNotFoundException::class);
});

// Helpers

function makeReadyVoiceClient(): VoiceClient
{
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();

    $readyProp = new \ReflectionProperty(VoiceClient::class, 'ready');
    $readyProp->setAccessible(true);
    $readyProp->setValue($vc, true);

    $speakingProp = new \ReflectionProperty(VoiceClient::class, 'speaking');
    $speakingProp->setAccessible(true);
    $speakingProp->setValue($vc, 0);

    return $vc;
}

function capturePlayFileRejection(\React\Promise\PromiseInterface $promise): mixed
{
    $rejection = null;
    $promise->then(null, function ($e) use (&$rejection) {
        $rejection = $e;
    });

    return $rejection;
}
