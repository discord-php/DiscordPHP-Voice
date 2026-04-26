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

use Discord\Voice\Speaking;
use Discord\Voice\VoiceClient;

it('updateSpeakingStatus() populates speakingStatus with the given speaking object', function (): void {
    [$vc, $speakingStatusProp, $ssrcMapProp] = makeVcForUpdateSpeaking();

    $speaking = makeSpeakingForUpdate(1234, 'user-abc');

    $vc->updateSpeakingStatus($speaking);

    $status = $speakingStatusProp->getValue($vc);
    expect($status)->toHaveKey('user-abc')
        ->and($status['user-abc'])->toBe($speaking);
});

it('updateSpeakingStatus() populates ssrcToUserId when ssrc is set', function (): void {
    [$vc, , $ssrcMapProp] = makeVcForUpdateSpeaking();

    $speaking = makeSpeakingForUpdate(9876, 'user-xyz');

    $vc->updateSpeakingStatus($speaking);

    expect($ssrcMapProp->getValue($vc))->toBe([9876 => 'user-xyz']);
});

it('updateSpeakingStatus() does not populate ssrcToUserId when ssrc is null', function (): void {
    [$vc, , $ssrcMapProp] = makeVcForUpdateSpeaking();

    $speaking = makeSpeakingForUpdate(null, 'user-no-ssrc');

    $vc->updateSpeakingStatus($speaking);

    expect($ssrcMapProp->getValue($vc))->toBeEmpty();
});

it('updateSpeakingStatus() overwrites a previous entry for the same user', function (): void {
    [$vc, $speakingStatusProp, $ssrcMapProp] = makeVcForUpdateSpeaking();

    $first = makeSpeakingForUpdate(11, 'user-dup');
    $vc->updateSpeakingStatus($first);

    $second = makeSpeakingForUpdate(22, 'user-dup');
    $vc->updateSpeakingStatus($second);

    $status = $speakingStatusProp->getValue($vc);
    expect($status['user-dup'])->toBe($second)
        ->and($ssrcMapProp->getValue($vc))->toHaveKey(22);
});

// Helpers

function makeVcForUpdateSpeaking(): array
{
    $vc = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();

    $speakingStatusProp = new \ReflectionProperty(VoiceClient::class, 'speakingStatus');
    $speakingStatusProp->setAccessible(true);
    $speakingStatusProp->setValue($vc, []);

    $ssrcMapProp = new \ReflectionProperty(VoiceClient::class, 'ssrcToUserId');
    $ssrcMapProp->setAccessible(true);
    $ssrcMapProp->setValue($vc, []);

    return [$vc, $speakingStatusProp, $ssrcMapProp];
}

function makeSpeakingForUpdate(?int $ssrc, string $userId): object
{
    $speaking = (new \ReflectionClass(Speaking::class))->newInstanceWithoutConstructor();
    $attrs = new \ReflectionProperty(Speaking::class, 'attributes');
    $attrs->setAccessible(true);
    $attrs->setValue($speaking, [
        'ssrc' => $ssrc,
        'user_id' => $userId,
        'speaking' => 1,
        'delay' => 0,
    ]);

    return $speaking;
}
