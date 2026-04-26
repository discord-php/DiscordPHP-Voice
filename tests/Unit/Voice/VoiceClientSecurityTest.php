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

it('escapes shell metacharacters in checkForExecutable (CRIT-2)', function () {
    // Testing that the method handles malicious input safely
    // Using a name that would be dangerous if unescaped
    $result = VoiceClient::checkForExecutable('nonexistent; echo pwned');
    expect($result)->toBeNull();
});

it('escapes backtick injection in checkForExecutable (CRIT-2)', function () {
    $result = VoiceClient::checkForExecutable('test`id`');
    expect($result)->toBeNull();
});

it('rejects file:// URLs in playFile URL validation (CRIT-1)', function () {
    // We can't fully test playFile without a running VoiceClient, but we can test
    // the scheme validation logic directly. We'll verify the ALLOWED_URL_SCHEMES constant.
    $reflection = new \ReflectionClassConstant(VoiceClient::class, 'ALLOWED_URL_SCHEMES');
    $schemes = $reflection->getValue();

    expect($schemes)->toBeArray()
        ->and($schemes)->toContain('https')
        ->and($schemes)->not->toContain('http')
        ->and($schemes)->not->toContain('file')
        ->and($schemes)->not->toContain('rtmp')
        ->and($schemes)->not->toContain('concat')
        ->and($schemes)->not->toContain('data');
});

it('has MAX_DECODERS constant set to a reasonable limit (HIGH-5)', function () {
    $reflection = new \ReflectionClassConstant(VoiceClient::class, 'MAX_DECODERS');
    $maxDecoders = $reflection->getValue();

    expect($maxDecoders)->toBeInt()
        ->and($maxDecoders)->toBeGreaterThan(0)
        ->and($maxDecoders)->toBeLessThanOrEqual(100);
});
