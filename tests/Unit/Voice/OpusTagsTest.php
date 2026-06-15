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

use Discord\Voice\OpusTags;
use UnexpectedValueException;

it('parses vendors and tags from opus comments', function (): void {
    $vendor = 'discord-php';
    $expectedTags = ['ARTIST=Coverage Bot', 'TITLE=Parser Test'];

    $data = 'OpusTags'.pack('V', strlen($vendor)).$vendor.pack('V', count($expectedTags));
    foreach ($expectedTags as $tag) {
        $data .= pack('V', strlen($tag)).$tag;
    }

    $tags = new OpusTags($data);

    expect($tags->vendor)->toBe($vendor)
        ->and($tags->tags)->toBe($expectedTags);
});

it('parses empty vendors without comment tags', function (): void {
    $tags = new OpusTags('OpusTags'.pack('V', 0).pack('V', 0));

    expect($tags->vendor)->toBe('')
        ->and($tags->tags)->toBe([]);
});

it('rejects invalid opus tags magic headers', function (): void {
    expect(fn () => new OpusTags('BadMagic'))
        ->toThrow(UnexpectedValueException::class, 'Expected OpusTags, found '.bin2hex('BadMagic'));
});
