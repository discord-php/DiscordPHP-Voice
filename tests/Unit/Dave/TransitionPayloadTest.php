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

namespace Discord\Tests\Unit\Dave;

use Discord\Voice\Dave\TransitionPayload;

it('parse returns null for empty string', function (): void {
    expect(TransitionPayload::parse(''))->toBeNull();
});

it('parse returns null for single byte', function (): void {
    expect(TransitionPayload::parse("\x00"))->toBeNull();
});

it('parse extracts transition_id from first two bytes', function (): void {
    // Big-endian uint16: 0x00 0x05 = 5
    $tp = TransitionPayload::parse("\x00\x05some payload");

    expect($tp)->toBeInstanceOf(TransitionPayload::class)
        ->and($tp->transitionId)->toBe(5);
});

it('parse extracts remaining bytes as payload', function (): void {
    $raw = pack('n', 42)."mls\x00data";
    $tp = TransitionPayload::parse($raw);

    expect($tp)->toBeInstanceOf(TransitionPayload::class)
        ->and($tp->payload)->toBe("mls\x00data");
});

it('parse returns empty payload string when only two header bytes present', function (): void {
    $tp = TransitionPayload::parse(pack('n', 7));

    expect($tp)->toBeInstanceOf(TransitionPayload::class)
        ->and($tp->transitionId)->toBe(7)
        ->and($tp->payload)->toBe('');
});

it('isZeroTransition returns true for transition_id 0', function (): void {
    $tp = TransitionPayload::parse(pack('n', 0).'payload');

    expect($tp?->isZeroTransition())->toBeTrue();
});

it('isZeroTransition returns false for non-zero transition_id', function (): void {
    $tp = TransitionPayload::parse(pack('n', 1).'payload');

    expect($tp?->isZeroTransition())->toBeFalse();
});
