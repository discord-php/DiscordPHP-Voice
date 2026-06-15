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

use Discord\Voice\RecieveStream;
use Evenement\EventEmitter;
use React\Stream\WritableStreamInterface;

it('writes PCM, Opus, and duplex chunks to their respective listeners', function (): void {
    $stream = new RecieveStream();
    $pcmEvents = [];
    $opusEvents = [];

    $stream->on('pcm', function (string $data) use (&$pcmEvents): void {
        $pcmEvents[] = $data;
    });
    $stream->on('opus', function (string $data) use (&$opusEvents): void {
        $opusEvents[] = $data;
    });

    expect($stream->isReadable())->toBeTrue()
        ->and($stream->isWritable())->toBeTrue();

    $stream->writePCM('pcm-');
    $stream->writeOpus('opus-');
    $stream->write('both');

    expect($pcmEvents)->toBe(['pcm-', 'both'])
        ->and($opusEvents)->toBe(['opus-', 'both'])
        ->and(readProperty($stream, 'pcmData'))->toBe('pcm-both')
        ->and(readProperty($stream, 'opusData'))->toBe('opus-both');
});

it('buffers paused data and flushes it only once when resumed', function (): void {
    $stream = new RecieveStream();
    $pcmEvents = [];
    $opusEvents = [];

    $stream->on('pcm', function (string $data) use (&$pcmEvents): void {
        $pcmEvents[] = $data;
    });
    $stream->on('opus', function (string $data) use (&$opusEvents): void {
        $opusEvents[] = $data;
    });

    $stream->pause();

    expect($stream->isReadable())->toBeFalse()
        ->and($stream->isWritable())->toBeFalse();

    $stream->writePCM('pcm-1');
    $stream->writeOpus('opus-1');
    $stream->write('both-1');

    expect($pcmEvents)->toBe([])
        ->and($opusEvents)->toBe([])
        ->and(readProperty($stream, 'pcmPauseBuffer'))->toBe(['pcm-1', 'both-1'])
        ->and(readProperty($stream, 'opusPauseBuffer'))->toBe(['opus-1', 'both-1']);

    $stream->resume();

    expect($stream->isReadable())->toBeTrue()
        ->and($stream->isWritable())->toBeTrue()
        ->and($pcmEvents)->toBe(['pcm-1', 'both-1'])
        ->and($opusEvents)->toBe(['opus-1', 'both-1'])
        ->and(readProperty($stream, 'pcmPauseBuffer'))->toBe([])
        ->and(readProperty($stream, 'opusPauseBuffer'))->toBe([]);

    $stream->pause();
    $stream->writePCM('pcm-2');
    $stream->resume();

    expect($pcmEvents)->toBe(['pcm-1', 'both-1', 'pcm-2'])
        ->and($opusEvents)->toBe(['opus-1', 'both-1']);
});

it('ends without final data and ignores subsequent operations once closed', function (): void {
    $stream = new RecieveStream();
    $events = [];

    $stream->on('pcm', function (string $data) use (&$events): void {
        $events[] = "pcm:{$data}";
    });
    $stream->on('opus', function (string $data) use (&$events): void {
        $events[] = "opus:{$data}";
    });
    $stream->on('end', function () use (&$events): void {
        $events[] = 'end';
    });
    $stream->on('close', function () use (&$events): void {
        $events[] = 'close';
    });

    $stream->end();

    expect($events)->toBe(['end', 'close'])
        ->and($stream->isReadable())->toBeFalse()
        ->and($stream->isWritable())->toBeFalse();

    $stream->writePCM('ignored');
    $stream->writeOpus('ignored');
    $stream->write('ignored');
    $stream->pause();
    $stream->resume();
    $stream->end('ignored');
    $stream->close();

    expect($events)->toBe(['end', 'close']);
});

it('writes final data to both codecs before ending', function (): void {
    $stream = new RecieveStream();
    $pcmEvents = [];
    $opusEvents = [];
    $lifecycle = [];

    $stream->on('pcm', function (string $data) use (&$pcmEvents): void {
        $pcmEvents[] = $data;
    });
    $stream->on('opus', function (string $data) use (&$opusEvents): void {
        $opusEvents[] = $data;
    });
    $stream->on('end', function () use (&$lifecycle): void {
        $lifecycle[] = 'end';
    });
    $stream->on('close', function () use (&$lifecycle): void {
        $lifecycle[] = 'close';
    });

    $stream->end('final');

    expect($pcmEvents)->toBe(['final'])
        ->and($opusEvents)->toBe(['final'])
        ->and($lifecycle)->toBe(['end', 'close'])
        ->and(readProperty($stream, 'pcmData'))->toBe('final')
        ->and(readProperty($stream, 'opusData'))->toBe('final');
});

it('pipePCM pauses on backpressure, resumes on drain, and ends the destination by default', function (): void {
    $source = new RecieveStream();
    $destination = new RecordingWritableStream([false, true]);

    $source->pipePCM($destination);
    $source->writePCM('first');

    expect($destination->writes)->toBe(['first'])
        ->and($source->isReadable())->toBeFalse();

    $source->writePCM('second');

    expect($destination->writes)->toBe(['first'])
        ->and(readProperty($source, 'pcmPauseBuffer'))->toBe(['second']);

    $destination->emitDrain();

    expect($destination->writes)->toBe(['first', 'second'])
        ->and($source->isReadable())->toBeTrue()
        ->and(readProperty($source, 'pcmPauseBuffer'))->toBe([]);

    $source->close();

    expect($destination->endCalls)->toBe(1)
        ->and($destination->endPayloads)->toBe([null]);
});

it('pipeOpus can keep the destination open when ending is disabled', function (): void {
    $source = new RecieveStream();
    $destination = new RecordingWritableStream();

    $source->pipeOpus($destination, ['end' => false]);
    $source->writeOpus('voice');
    $source->close();

    expect($destination->writes)->toBe(['voice'])
        ->and($destination->endCalls)->toBe(0)
        ->and($destination->isWritable())->toBeTrue();
});

it('pipe forwards both codecs and only ends the destination once', function (): void {
    $source = new RecieveStream();
    $destination = new RecordingWritableStream();

    $source->pipe($destination);
    $source->writePCM('pcm');
    $source->writeOpus('opus');
    $source->end();

    expect($destination->writes)->toBe(['pcm', 'opus'])
        ->and($destination->endCalls)->toBe(1)
        ->and($destination->endPayloads)->toBe([null]);
});

function readProperty(object $object, string $property): mixed
{
    $reflectionProperty = new \ReflectionProperty($object, $property);
    $reflectionProperty->setAccessible(true);

    return $reflectionProperty->getValue($object);
}

final class RecordingWritableStream extends EventEmitter implements WritableStreamInterface
{
    /**
     * @var list<mixed>
     */
    public array $writes = [];

    /**
     * @var list<mixed>
     */
    public array $endPayloads = [];

    public int $endCalls = 0;

    private bool $writable = true;

    /**
     * @var list<bool>
     */
    private array $writeResponses;

    /**
     * @param list<bool> $writeResponses
     */
    public function __construct(array $writeResponses = [])
    {
        $this->writeResponses = $writeResponses;
    }

    public function isWritable()
    {
        return $this->writable;
    }

    public function write($data)
    {
        if (! $this->writable) {
            return false;
        }

        $this->writes[] = $data;

        if ($this->writeResponses === []) {
            return true;
        }

        return array_shift($this->writeResponses);
    }

    public function end($data = null)
    {
        $this->endCalls++;
        $this->endPayloads[] = $data;

        if (! $this->writable) {
            return;
        }

        if (null !== $data) {
            $this->writes[] = $data;
        }

        $this->writable = false;
        $this->emit('close', []);
    }

    public function close()
    {
        if (! $this->writable) {
            return;
        }

        $this->writable = false;
        $this->emit('close', []);
    }

    public function emitDrain(): void
    {
        $this->emit('drain', []);
    }
}
