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

use Discord\Voice\OpusHead;
use UnexpectedValueException;

it('parses opus heads without channel mapping', function (): void {
    $head = new OpusHead('OpusHead'.pack('CCvVvC', 1, 2, 312, 48000, 6, 0));

    expect($head->version)->toBe(1)
        ->and($head->channelCount)->toBe(2)
        ->and($head->preSkip)->toBe(312)
        ->and($head->sampleRate)->toBe(48000)
        ->and($head->outputGain)->toBe(6)
        ->and($head->channelMapFamily)->toBe(0)
        ->and($head->streamCount)->toBeNull()
        ->and($head->twoChannelStreamCount)->toBeNull()
        ->and($head->cmap)->toBeNull();
});

it('parses optional channel mapping data', function (): void {
    $cmap = [0, 4, 1, 2, 3, 5];
    $head = new OpusHead(
        'OpusHead'
        .pack('CCvVvC', 1, 6, 384, 48000, 12, 1)
        .pack('CC', 4, 2)
        .pack('C*', ...$cmap)
    );

    expect($head->channelCount)->toBe(6)
        ->and($head->channelMapFamily)->toBe(1)
        ->and($head->streamCount)->toBe(4)
        ->and($head->twoChannelStreamCount)->toBe(2)
        ->and($head->cmap)->toBe($cmap);
});

it('rejects invalid opus head magic headers', function (): void {
    expect(fn () => new OpusHead('BadMagic'))
        ->toThrow(UnexpectedValueException::class, 'Expected OpusHead, found '.bin2hex('BadMagic'));
});
