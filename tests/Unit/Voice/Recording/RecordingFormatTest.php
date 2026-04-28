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

namespace Discord\Tests\Unit\Voice\Recording;

use Discord\Voice\Recording\RecordingFormat;

it('RecordingFormat has exactly three cases', function (): void {
    expect(RecordingFormat::cases())->toHaveCount(3);
});

it('PCM has extension pcm and does not require ffmpeg', function (): void {
    expect(RecordingFormat::PCM->extension())->toBe('pcm')
        ->and(RecordingFormat::PCM->requiresFfmpeg())->toBeFalse();
});

it('WAV has extension wav and does not require ffmpeg', function (): void {
    expect(RecordingFormat::WAV->extension())->toBe('wav')
        ->and(RecordingFormat::WAV->requiresFfmpeg())->toBeFalse();
});

it('OGG has extension ogg and requires ffmpeg', function (): void {
    expect(RecordingFormat::OGG->extension())->toBe('ogg')
        ->and(RecordingFormat::OGG->requiresFfmpeg())->toBeTrue();
});

it('label() returns a non-empty string for all cases', function (): void {
    foreach (RecordingFormat::cases() as $case) {
        expect($case->label())->toBeString()->not->toBeEmpty();
    }
});

it('from() resolves PCM WAV OGG by string value', function (): void {
    expect(RecordingFormat::from('pcm'))->toBe(RecordingFormat::PCM)
        ->and(RecordingFormat::from('wav'))->toBe(RecordingFormat::WAV)
        ->and(RecordingFormat::from('ogg'))->toBe(RecordingFormat::OGG);
});
