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

use Discord\Voice\Dave\BinaryFrame;

it('round trips server payloads with header values and binary payloads', function (): void {
    $payload = pack('nC', 513, 7)."mls:\x00ok";
    $frame = BinaryFrame::fromPayload($payload);

    expect($frame)->toBeInstanceOf(BinaryFrame::class);
    /** @var BinaryFrame $frame */
    expect($frame->sequence)->toBe(513)
        ->and($frame->opcode)->toBe(7)
        ->and($frame->payload)->toBe("mls:\x00ok")
        ->and($frame->toPayload())->toBe($payload);
});

it('round trips empty server payload bodies with max header values', function (): void {
    $frame = new BinaryFrame(65535, 255);
    $payload = $frame->toPayload();

    expect($payload)->toBe(pack('nC', 65535, 255));

    $parsedFrame = BinaryFrame::fromPayload($payload);
    expect($parsedFrame)->toBeInstanceOf(BinaryFrame::class);
    /** @var BinaryFrame $parsedFrame */
    expect($parsedFrame->sequence)->toBe(65535)
        ->and($parsedFrame->opcode)->toBe(255)
        ->and($parsedFrame->payload)->toBe('');
});

it('round trips client payloads without a sequence number', function (): void {
    $payload = pack('C', 17)."hello\x00";
    $frame = BinaryFrame::fromClientPayload($payload);

    expect($frame)->toBeInstanceOf(BinaryFrame::class);
    /** @var BinaryFrame $frame */
    expect($frame->sequence)->toBeNull()
        ->and($frame->opcode)->toBe(17)
        ->and($frame->payload)->toBe("hello\x00")
        ->and($frame->toClientPayload())->toBe($payload);
});

it('returns null for incomplete server payloads', function (string $payload): void {
    expect(BinaryFrame::fromPayload($payload))->toBeNull();
})->with([
    'empty payload' => '',
    'one byte' => "\x01",
    'two bytes' => "\x01\x02",
]);

it('returns null for incomplete client payloads', function (): void {
    expect(BinaryFrame::fromClientPayload(''))->toBeNull();
});

it('requires a sequence number for server frame serialization', function (): void {
    expect(fn (): string => (new BinaryFrame(null, 1, 'payload'))->toPayload())
        ->toThrow(\RuntimeException::class, 'Server DAVE binary frames require a sequence number.');
});
