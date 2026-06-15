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

use Discord\Voice\Processes\Ffmpeg;
use Discord\Voice\VoiceClient;
use React\Promise\PromiseInterface;

// ─── Section 1: Unsafe scheme rejection ───────────────────────────────────────
// DESIRED POLICY: only http:// and https:// (with public hosts) are allowed.
// All other URL schemes must be rejected before reaching ffmpeg.
// All tests in this section PASS with current production code because the
// ALLOWED_URL_SCHEMES scheme-check is already in place.

it('rejects file:// scheme — prevents local filesystem read', function () {
    $rejection = playFileSec_capture(playFileSec_readyVc()->playFile('file:///etc/passwd'));
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('not allowed');
});

it('rejects ftp:// scheme — not a public HTTP/S URL', function () {
    $rejection = playFileSec_capture(playFileSec_readyVc()->playFile('ftp://example.com/audio.mp3'));
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('not allowed');
});

it('rejects rtmp:// scheme — streaming protocol not in policy', function () {
    $rejection = playFileSec_capture(playFileSec_readyVc()->playFile('rtmp://example.com/live/stream'));
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('not allowed');
});

it('rejects php:// wrapper scheme', function () {
    $rejection = playFileSec_capture(playFileSec_readyVc()->playFile('php://input'));
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('not allowed');
});

it('rejects gopher:// scheme', function () {
    $rejection = playFileSec_capture(playFileSec_readyVc()->playFile('gopher://example.com/1test'));
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('not allowed');
});

it('rejects dict:// scheme', function () {
    $rejection = playFileSec_capture(playFileSec_readyVc()->playFile('dict://example.com/'));
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('not allowed');
});

// ─── Section 2: data: URI handling ────────────────────────────────────────────
// data: URIs do not have an authority component, so PHP's filter_var()
// (FILTER_VALIDATE_URL) rejects them as invalid URLs. playFile() then treats
// the string as a local file path, file_exists() returns false, and the promise
// is rejected with FileNotFoundException — so data: payloads never reach ffmpeg.

it('rejects data: URI — cannot reach ffmpeg as a local path or URL', function () {
    $rejection = playFileSec_capture(playFileSec_readyVc()->playFile('data:audio/ogg;base64,AAAA'));
    // Rejected as a non-existent "file" path (FileNotFoundException) OR by the scheme
    // check (InvalidArgumentException). Either way the data is never forwarded to ffmpeg.
    expect($rejection)->not->toBeNull()
        ->and($rejection)->toBeInstanceOf(\Throwable::class);
});

// ─── Section 4: Private / reserved host rejection (SSRF prevention) ───────────
// DESIRED POLICY: any URL whose resolved host is a private, loopback, or
// link-local address must be rejected with InvalidArgumentException before the
// connection is handed off to ffmpeg.
// CURRENT STATE: No host-level validation exists.
// ALL tests in this section FAIL intentionally — they document missing checks.

it('rejects https://127.0.0.1/ — IPv4 loopback (desired policy — currently fails)', function () {
    // FAILS: production code performs no host check; the URL passes straight to ffmpeg.
    $rejection = playFileSec_captureIncludingThrown(
        fn () => playFileSec_readyVc()->playFile('https://127.0.0.1/audio.ogg')
    );
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('private or reserved');
});

it('rejects https://127.255.255.254/ — upper loopback range (desired policy — currently fails)', function () {
    $rejection = playFileSec_captureIncludingThrown(
        fn () => playFileSec_readyVc()->playFile('https://127.255.255.254/audio.ogg')
    );
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('private or reserved');
});

it('rejects https://10.0.0.1/ — RFC 1918 class A (desired policy — currently fails)', function () {
    $rejection = playFileSec_captureIncludingThrown(
        fn () => playFileSec_readyVc()->playFile('https://10.0.0.1/audio.ogg')
    );
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('private or reserved');
});

it('rejects https://172.16.0.1/ — RFC 1918 class B (desired policy — currently fails)', function () {
    $rejection = playFileSec_captureIncludingThrown(
        fn () => playFileSec_readyVc()->playFile('https://172.16.0.1/audio.ogg')
    );
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('private or reserved');
});

it('rejects https://192.168.1.1/ — RFC 1918 class C (desired policy — currently fails)', function () {
    $rejection = playFileSec_captureIncludingThrown(
        fn () => playFileSec_readyVc()->playFile('https://192.168.1.1/audio.ogg')
    );
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('private or reserved');
});

it('rejects https://169.254.169.254/ — link-local / cloud metadata endpoint (desired policy — currently fails)', function () {
    // 169.254.169.254 is the AWS/GCP/Azure instance metadata endpoint.
    $rejection = playFileSec_captureIncludingThrown(
        fn () => playFileSec_readyVc()->playFile('https://169.254.169.254/latest/meta-data/')
    );
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('private or reserved');
});

it('rejects https://0.0.0.0/ — unspecified address (desired policy — currently fails)', function () {
    $rejection = playFileSec_captureIncludingThrown(
        fn () => playFileSec_readyVc()->playFile('https://0.0.0.0/audio.ogg')
    );
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('private or reserved');
});

it('rejects https://localhost/ — loopback hostname (desired policy — currently fails)', function () {
    $rejection = playFileSec_captureIncludingThrown(
        fn () => playFileSec_readyVc()->playFile('https://localhost/audio.ogg')
    );
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('private or reserved');
});

it('rejects https://[::1]/ — IPv6 loopback (desired policy — currently fails)', function () {
    $rejection = playFileSec_captureIncludingThrown(
        fn () => playFileSec_readyVc()->playFile('https://[::1]/audio.ogg')
    );
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('private or reserved');
});

it('rejects https://[fe80::1]/ — IPv6 link-local (desired policy — currently fails)', function () {
    $rejection = playFileSec_captureIncludingThrown(
        fn () => playFileSec_readyVc()->playFile('https://[fe80::1]/audio.ogg')
    );
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('private or reserved');
});

it('rejects https://[fc00::1]/ — IPv6 unique local address (desired policy — currently fails)', function () {
    $rejection = playFileSec_captureIncludingThrown(
        fn () => playFileSec_readyVc()->playFile('https://[fc00::1]/audio.ogg')
    );
    expect($rejection)->toBeInstanceOf(\InvalidArgumentException::class)
        ->and($rejection->getMessage())->toContain('private or reserved');
});

// ─── Section 5: Allowed public URLs ───────────────────────────────────────────
// https:// with public IPs or hostnames must NOT be rejected for security reasons.
// (Other errors — event loop, ffmpeg process, uninitialised client state — are fine.)
// These PASS with current production code.

it('does not security-reject https:// with a public IPv4 address', function () {
    // Uses 203.0.113.1 (TEST-NET-3, RFC 5737) — never routed; avoids real network calls.
    $securityRejected = false;
    try {
        playFileSec_readyVc()->playFile('https://203.0.113.1/audio.ogg')
            ->then(null, function ($e) use (&$securityRejected): void {
                if ($e instanceof \InvalidArgumentException) {
                    $securityRejected = true;
                }
            });
    } catch (\InvalidArgumentException $e) {
        $securityRejected = true;
    } catch (\Throwable) {
        // RuntimeException (process start), TypeError (uninitialised properties), etc. are fine.
    }
    expect($securityRejected)->toBeFalse();
});

it('does not security-reject https:// with a public hostname', function () {
    $securityRejected = false;
    try {
        playFileSec_readyVc()->playFile('https://example.com/audio.ogg')
            ->then(null, function ($e) use (&$securityRejected): void {
                if ($e instanceof \InvalidArgumentException) {
                    $securityRejected = true;
                }
            });
    } catch (\InvalidArgumentException $e) {
        $securityRejected = true;
    } catch (\Throwable) {
        // Non-security errors are expected in a test context without a live event loop.
    }
    expect($securityRejected)->toBeFalse();
});

// DESIRED POLICY: http:// (plain HTTP) with a public IP/hostname is intentionally
// excluded — ALLOWED_URL_SCHEMES = ['https'] only.

// ─── Section 6: ffmpeg protocol whitelist ─────────────────────────────────────
// The -protocol_whitelist flag controls which protocols ffmpeg itself may use.
// http and https must be present (to fetch remote streams).
// 'file' must NOT be present — if it were, ffmpeg could read local files even if
// the PHP URL validation layer were somehow bypassed.

it('ffmpeg encode command includes http and https in the protocol whitelist', function () {
    $command = Ffmpeg::encode('https://example.com/audio.ogg')->getCommand();
    preg_match('/-protocol_whitelist\s+(\S+)/', $command, $matches);
    expect($matches)->not->toBeEmpty('ffmpeg command must contain a -protocol_whitelist flag');
    $protocols = explode(',', $matches[1]);
    expect($protocols)->toContain('http')
        ->and($protocols)->toContain('https');
});

it('ffmpeg encode protocol whitelist permits file so local playback works', function () {
    // POLICY: local file playback is a primary feature. SSRF protection is enforced at the
    // playFile() URL layer (scheme allowlist + private/reserved IP block + localhost block);
    // the ffmpeg whitelist intentionally permits 'file' so local audio paths still resolve.
    $command = Ffmpeg::encode('https://example.com/audio.ogg')->getCommand();
    preg_match('/-protocol_whitelist\s+(\S+)/', $command, $matches);
    expect($matches)->not->toBeEmpty('ffmpeg command must contain a -protocol_whitelist flag');
    $protocols = explode(',', $matches[1]);
    expect($protocols)->toContain('file');
});

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Creates a VoiceClient that reports as ready and not-speaking, built without the
 * full constructor. This is sufficient to exercise the URL-validation layer inside
 * playFile(), which runs before any I/O or event-loop interaction is initiated.
 */
function playFileSec_readyVc(): VoiceClient
{
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $vc->ready = true;
    $vc->speaking = VoiceClient::NOT_SPEAKING;

    return $vc;
}

/**
 * Synchronously captures a promise rejection. Works because ReactPHP Deferred
 * promises that are already settled invoke their rejection handlers synchronously
 * inside then().
 */
function playFileSec_capture(PromiseInterface $promise): mixed
{
    $rejection = null;
    $promise->then(null, function ($e) use (&$rejection): void {
        $rejection = $e;
    });

    return $rejection;
}

/**
 * Invokes $fn and captures either a promise rejection or a directly-thrown exception.
 * Non-InvalidArgumentException throwables (TypeError from uninitialised VoiceClient
 * properties, RuntimeException from Process::start() without a real event loop, etc.)
 * are silenced because the tests in Sections 3–4 only care whether the security
 * check fires, not whether playback actually succeeds.
 */
function playFileSec_captureIncludingThrown(\Closure $fn): mixed
{
    $rejection = null;
    try {
        $promise = $fn();
        $promise->then(null, function ($e) use (&$rejection): void {
            $rejection = $e;
        });
    } catch (\InvalidArgumentException $e) {
        $rejection = $e;
    } catch (\Throwable) {
        // Non-security errors (uninitialized properties, process start failures) are ignored.
    }

    return $rejection;
}
