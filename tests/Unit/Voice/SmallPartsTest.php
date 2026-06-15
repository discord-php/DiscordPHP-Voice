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
use Discord\Parts\Voice\UserConnected;
use Discord\Voice\Client\HeaderValuesEnum;
use Discord\Voice\Client\User;
use Discord\Voice\Dave\DecryptorHandle;
use Discord\Voice\Dave\EncryptorHandle;
use Discord\Voice\Dave\KeyRatchetHandle;
use Discord\Voice\Dave\SessionHandle;
use Discord\Voice\ReceiveStream;
use Discord\Voice\VoiceClient;
use React\ChildProcess\Process;

it('constructs the connected-user value object', function (): void {
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $voiceClient = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $process = (new \ReflectionClass(Process::class))->newInstanceWithoutConstructor();
    $stream = new ReceiveStream();

    $user = new User($discord, $voiceClient, 12345, $process, $stream, null);

    $ssrc = (new \ReflectionProperty(User::class, 'ssrc'));
    $ssrc->setAccessible(true);

    expect($user)->toBeInstanceOf(User::class)
        ->and($ssrc->getValue($user))->toBe(12345);
});

it('exposes RTP header offset constants via the enum', function (): void {
    expect(HeaderValuesEnum::RTP_HEADER_OR_NONCE_LENGTH->value)->toBe(12)
        ->and(HeaderValuesEnum::RTP_VERSION_PAD_EXTEND_INDEX->value)->toBe(0)
        ->and(HeaderValuesEnum::RTP_VERSION_PAD_EXTEND->value)->toBe(0x80)
        ->and(HeaderValuesEnum::RTP_PAYLOAD_INDEX->value)->toBe(1)
        ->and(HeaderValuesEnum::RTP_PAYLOAD_TYPE->value)->toBe(0x78)
        ->and(HeaderValuesEnum::SEQ_INDEX->value)->toBe(2)
        ->and(HeaderValuesEnum::TIMESTAMP_OR_NONCE_INDEX->value)->toBe(4)
        ->and(HeaderValuesEnum::SSRC_INDEX->value)->toBe(8)
        ->and(HeaderValuesEnum::AUTH_TAG_LENGTH->value)->toBe(16);
});

it('wraps an opaque pointer for each DAVE handle type', function (string $class): void {
    $token = new \stdClass();
    /** @var \Discord\Voice\Dave\NativeHandle $handle */
    $handle = new $class($token);

    expect($handle->raw())->toBe($token);

    $handle->destroy();

    expect(fn () => $handle->raw())->toThrow(\RuntimeException::class);

    $handle->destroy();
})->with([
    [SessionHandle::class],
    [EncryptorHandle::class],
    [DecryptorHandle::class],
    [KeyRatchetHandle::class],
]);

it('declares the connected-user Part fillable surface', function (): void {
    $part = (new \ReflectionClass(UserConnected::class))->newInstanceWithoutConstructor();

    $fillable = (new \ReflectionProperty(UserConnected::class, 'fillable'));
    $fillable->setAccessible(true);

    expect($part)->toBeInstanceOf(UserConnected::class)
        ->and($fillable->getValue($part))->toBe(['user_id']);
});
