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

use Discord\Voice\Exceptions\BufferTimedOutException;
use Discord\Voice\Helpers\Buffer;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;

use function React\Async\await;

it('reads buffered bytes immediately and consumes them', function (): void {
    $buffer = new Buffer();
    $buffer->write('hello');

    expect(await($buffer->read(2)))->toBe('he')
        ->and(await($buffer->read(3)))->toBe('llo');
});

it('resolves deferred reads in order once enough bytes are written', function (): void {
    $buffer = new Buffer();

    $firstRead = trackPromise($buffer->read(2));
    $secondRead = trackPromise($buffer->read(3));

    expect($firstRead->resolved)->toBeFalse()
        ->and($secondRead->resolved)->toBeFalse();

    $buffer->write('ab');

    expect($firstRead->resolved)->toBeTrue()
        ->and($firstRead->value)->toBe('ab')
        ->and($firstRead->rejected)->toBeFalse()
        ->and($secondRead->resolved)->toBeFalse();

    $buffer->write('cde');

    expect($secondRead->resolved)->toBeTrue()
        ->and($secondRead->value)->toBe('cde')
        ->and($secondRead->rejected)->toBeFalse();
});

it('rejects zero-timeout reads without consuming future writes', function (): void {
    $buffer = new Buffer();

    expect(fn () => await($buffer->read(2, timeout: 0)))
        ->toThrow(BufferTimedOutException::class, 'Reading from the buffer timed out.');

    $buffer->write('ok');

    expect(await($buffer->read(2)))->toBe('ok');
});

it('cancels pending timers when a deferred read resolves', function (): void {
    $loop = new RecordingLoop();
    $buffer = new Buffer($loop);
    $read = trackPromise($buffer->read(2, timeout: 50));
    $timer = $loop->timers[0];

    expect($loop->timers)->toHaveCount(1)
        ->and($timer->getInterval())->toBe(0.05)
        ->and($read->resolved)->toBeFalse()
        ->and($read->rejected)->toBeFalse();

    $buffer->write('ok');

    expect($read->resolved)->toBeTrue()
        ->and($read->value)->toBe('ok')
        ->and($read->rejected)->toBeFalse()
        ->and($loop->cancelledTimers)->toBe([$timer])
        ->and($loop->timers)->toBe([]);
});

it('unpacks formatted reads and integer helpers', function (): void {
    $buffer = new Buffer();
    $buffer->write(pack('v', 513).pack('v', 1024).pack('l', -123456789));

    expect(await($buffer->read(2, 'v')))->toBe(513)
        ->and(await($buffer->readInt16()))->toBe(1024)
        ->and(await($buffer->readInt32()))->toBe(-123456789);
});

it('ends with final data, closes once, and rejects writes after closing', function (): void {
    $buffer = new Buffer();
    $closeEvents = 0;

    $buffer->on('close', function () use (&$closeEvents): void {
        ++$closeEvents;
    });

    expect($buffer->isWritable())->toBeTrue();

    $buffer->write('hi');
    $buffer->end('!');

    expect($closeEvents)->toBe(1)
        ->and($buffer->isWritable())->toBeFalse()
        ->and(await($buffer->read(3)))->toBe('hi!')
        ->and($buffer->write('?'))->toBeFalse();

    $buffer->close();

    expect($closeEvents)->toBe(1)
        ->and(fn () => await($buffer->read(1, timeout: 0)))
        ->toThrow(\RuntimeException::class, 'Buffer closed');
});

function trackPromise(PromiseInterface $promise): object
{
    $state = (object) [
        'resolved' => false,
        'rejected' => false,
        'value' => null,
        'reason' => null,
    ];

    $promise->then(
        function (mixed $value) use ($state): void {
            $state->resolved = true;
            $state->value = $value;
        },
        function (mixed $reason) use ($state): void {
            $state->rejected = true;
            $state->reason = $reason;
        }
    );

    return $state;
}

final class RecordingLoop implements LoopInterface
{
    /**
     * @var list<RecordingTimer>
     */
    public array $timers = [];

    /**
     * @var list<RecordingTimer>
     */
    public array $cancelledTimers = [];

    public function addReadStream($stream, $listener)
    {
    }

    public function addWriteStream($stream, $listener)
    {
    }

    public function removeReadStream($stream)
    {
    }

    public function removeWriteStream($stream)
    {
    }

    public function addTimer($interval, $callback)
    {
        $timer = new RecordingTimer((float) $interval, $callback);
        $this->timers[] = $timer;

        return $timer;
    }

    public function addPeriodicTimer($interval, $callback)
    {
        return new RecordingTimer((float) $interval, $callback, true);
    }

    public function cancelTimer(TimerInterface $timer)
    {
        if (! $timer instanceof RecordingTimer || $timer->cancelled) {
            return;
        }

        $timer->cancelled = true;
        $this->cancelledTimers[] = $timer;
        $this->timers = array_values(array_filter(
            $this->timers,
            fn (RecordingTimer $scheduledTimer): bool => $scheduledTimer !== $timer
        ));
    }

    public function futureTick($listener)
    {
    }

    public function addSignal($signal, $listener)
    {
    }

    public function removeSignal($signal, $listener)
    {
    }

    public function run()
    {
    }

    public function stop()
    {
    }
}

final class RecordingTimer implements TimerInterface
{
    public bool $cancelled = false;

    public function __construct(
        private float $interval,
        private $callback,
        private bool $periodic = false
    ) {
    }

    public function getInterval()
    {
        return $this->interval;
    }

    public function getCallback()
    {
        return $this->callback;
    }

    public function isPeriodic()
    {
        return $this->periodic;
    }
}
