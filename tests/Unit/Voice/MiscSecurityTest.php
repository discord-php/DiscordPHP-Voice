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

use Discord\Factory\SocketFactory;
use Discord\Voice\RecieveStream;

it('throws when SocketFactory receives null resolver and null WS (MED-6)', function () {
    new SocketFactory(null, null, null);
})->throws(\InvalidArgumentException::class);

it('caps PCM pause buffer at MAX_PAUSE_BUFFER (MED-8)', function () {
    $stream = new RecieveStream();

    // Pause the stream
    $stream->pause();

    // Write more than MAX_PAUSE_BUFFER frames
    $reflection = new \ReflectionClass(RecieveStream::class);
    $maxBuffer = $reflection->getConstant('MAX_PAUSE_BUFFER');

    for ($i = 0; $i < $maxBuffer + 100; $i++) {
        $stream->writePCM('test-pcm-data');
    }

    $prop = new \ReflectionProperty(RecieveStream::class, 'pcmPauseBuffer');
    $prop->setAccessible(true);
    $bufferSize = count($prop->getValue($stream));

    expect($bufferSize)->toBe($maxBuffer);
});

it('caps Opus pause buffer at MAX_PAUSE_BUFFER (MED-8)', function () {
    $stream = new RecieveStream();
    $stream->pause();

    $reflection = new \ReflectionClass(RecieveStream::class);
    $maxBuffer = $reflection->getConstant('MAX_PAUSE_BUFFER');

    for ($i = 0; $i < $maxBuffer + 100; $i++) {
        $stream->writeOpus('test-opus-data');
    }

    $prop = new \ReflectionProperty(RecieveStream::class, 'opusPauseBuffer');
    $prop->setAccessible(true);
    $bufferSize = count($prop->getValue($stream));

    expect($bufferSize)->toBe($maxBuffer);
});
