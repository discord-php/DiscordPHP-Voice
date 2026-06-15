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

use Discord\Voice\Dave\Runtime as DaveRuntime;
use Discord\Voice\Exceptions\BufferTimedOutException;
use Discord\Voice\Exceptions\Channels\AudioAlreadyPlayingException;
use Discord\Voice\Exceptions\Channels\CantJoinMoreThanOneChannelException;
use Discord\Voice\Exceptions\Channels\CantSpeakInChannelException;
use Discord\Voice\Exceptions\Channels\ChannelMustAllowVoiceException;
use Discord\Voice\Exceptions\Channels\EnterChannelDeniedException;
use Discord\Voice\Exceptions\ClientNotReadyException;
use Discord\Voice\Exceptions\Libraries\DCANotFoundException;
use Discord\Voice\Exceptions\Libraries\FFmpegNotFoundException;
use Discord\Voice\Exceptions\Libraries\LibDaveNotFoundException;
use Discord\Voice\Exceptions\Libraries\LibSodiumNotFoundException;
use Discord\Voice\Exceptions\Libraries\OpusNotFoundException;
use Discord\Voice\Exceptions\Libraries\OutdatedDCAException;
use Discord\Voice\Exceptions\VoiceException;

afterEach(function (): void {
    DaveRuntime::reset();
});

it('VoiceException is an interface extending Throwable', function (): void {
    $reflection = new \ReflectionClass(VoiceException::class);
    expect($reflection->isInterface())->toBeTrue();
    expect($reflection->implementsInterface(\Throwable::class))->toBeTrue();
});

it('BufferTimedOutException has the expected message and base class', function (): void {
    $e = new BufferTimedOutException();
    expect($e)->toBeInstanceOf(\RuntimeException::class)
        ->and($e)->toBeInstanceOf(VoiceException::class)
        ->and($e->getMessage())->toBe('Reading from the buffer timed out.');
});

it('ClientNotReadyException defaults message and accepts override', function (): void {
    $default = new ClientNotReadyException();
    expect($default)->toBeInstanceOf(\RuntimeException::class)
        ->and($default)->toBeInstanceOf(VoiceException::class)
        ->and($default->getMessage())->toBe('Voice Client is not ready.');

    $custom = new ClientNotReadyException('custom not ready');
    expect($custom->getMessage())->toBe('custom not ready');
});

dataset('channel_exceptions', [
    'AudioAlreadyPlaying' => [AudioAlreadyPlayingException::class, 'Audio is already playing.'],
    'CantJoinMoreThanOneChannel' => [CantJoinMoreThanOneChannelException::class, 'You cannot join more than one voice channel per guild/server.'],
    'CantSpeakInChannel' => [CantSpeakInChannelException::class, 'The current Channel doesn\'t have proper permissions for the Bot to speak in it.'],
    'ChannelMustAllowVoice' => [ChannelMustAllowVoiceException::class, 'Current Channel must allow voice.'],
    'EnterChannelDenied' => [EnterChannelDeniedException::class, 'The current Channel doesn\'t have proper permissions for the Bot to connect to it.'],
]);

it('Channel exceptions extend RuntimeException, implement VoiceException, and use default message', function (string $class, string $expected): void {
    $e = new $class();
    expect($e)->toBeInstanceOf(\RuntimeException::class)
        ->and($e)->toBeInstanceOf(VoiceException::class)
        ->and($e->getMessage())->toBe($expected);
})->with('channel_exceptions');

it('Channel exceptions accept a custom message', function (string $class): void {
    $e = new $class('custom message');
    expect($e->getMessage())->toBe('custom message');
})->with('channel_exceptions');

dataset('library_exceptions', [
    'DCANotFound' => [DCANotFoundException::class],
    'FFmpegNotFound' => [FFmpegNotFoundException::class],
    'LibDaveNotFound' => [LibDaveNotFoundException::class],
    'LibSodiumNotFound' => [LibSodiumNotFoundException::class],
    'OpusNotFound' => [OpusNotFoundException::class],
    'OutdatedDCA' => [OutdatedDCAException::class],
]);

it('Library exceptions extend Exception and implement VoiceException', function (string $class): void {
    $e = new $class('lib message');
    expect($e)->toBeInstanceOf(\Exception::class)
        ->and($e)->toBeInstanceOf(VoiceException::class)
        ->and($e->getMessage())->toBe('lib message');
})->with('library_exceptions');

it('LibDaveNotFoundException::fromRuntimeError uses provided message directly without appending load error', function (): void {
    setLastLoadError('some unrelated load error');
    $e = LibDaveNotFoundException::fromRuntimeError('libdave library not found in package cache.');

    expect($e)->toBeInstanceOf(LibDaveNotFoundException::class)
        ->and($e->getMessage())->toBe('libdave library not found in package cache.')
        ->and($e->getMessage())->not->toContain('Load error:');
});

it('LibDaveNotFoundException::fromRuntimeError builds base message without load error suffix when error is empty', function (): void {
    setLastLoadError('');
    $e = LibDaveNotFoundException::fromRuntimeError();

    expect($e)->toBeInstanceOf(LibDaveNotFoundException::class)
        ->and($e)->toBeInstanceOf(VoiceException::class)
        ->and($e->getMessage())->toContain('libdave is required but could not be loaded.')
        ->and($e->getMessage())->toContain('March 1st, 2026')
        ->and($e->getMessage())->toContain('https://discord.com/developers/docs/change-log#future-deprecation-and-discontinuation-of-non-e2ee-voice')
        ->and($e->getMessage())->toContain('bash scripts/setup-libdave.sh')
        ->and($e->getMessage())->toContain('https://github.com/discord/libdave')
        ->and($e->getMessage())->not->toContain('Load error:');
});

it('LibDaveNotFoundException::fromRuntimeError appends a real runtime error string', function (): void {
    setLastLoadError('FFI parser error: missing symbol foo');
    $e = LibDaveNotFoundException::fromRuntimeError();

    expect($e->getMessage())->toContain("\nLoad error: FFI parser error: missing symbol foo");
});

it('LibDaveNotFoundException::fromRuntimeError handles a null load error', function (): void {
    setLastLoadError(null);
    $e = LibDaveNotFoundException::fromRuntimeError();

    expect($e)->toBeInstanceOf(LibDaveNotFoundException::class)
        ->and($e->getMessage())->toContain('libdave is required but could not be loaded.');
});

// Helpers

function setLastLoadError(?string $value): void
{
    $property = new \ReflectionProperty(DaveRuntime::class, 'lastLoadError');
    $property->setAccessible(true);
    $property->setValue(null, $value);
}
