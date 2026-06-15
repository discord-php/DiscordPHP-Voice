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

use Discord\Voice\ReceiveStream;
use Discord\Voice\RecieveStream;

it('extends the legacy receive stream implementation', function (): void {
    $stream = new ReceiveStream();
    $pcmEvents = [];

    $stream->on('pcm', function (string $data) use (&$pcmEvents): void {
        $pcmEvents[] = $data;
    });

    $stream->writePCM('pcm');

    expect($stream)->toBeInstanceOf(ReceiveStream::class)
        ->and($stream)->toBeInstanceOf(RecieveStream::class)
        ->and($pcmEvents)->toBe(['pcm']);
});
