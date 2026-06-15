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

namespace Discord\Voice;

use Evenement\EventEmitterTrait;
use React\Stream\DuplexStreamInterface;
use React\Stream\WritableStreamInterface;

/**
 * Handles recieving audio from Discord.
 *
 * @deprecated The class was renamed, kept for backwards compatibility.
 * @since 3.2.0
 */
class RecieveStream implements DuplexStreamInterface
{
    use EventEmitterTrait;

    /**
     * Contains PCM data.
     *
     * @var string PCM data.
     */
    protected $pcmData = '';

    /**
     * Contains Opus data.
     *
     * @var string Opus data.
     */
    protected $opusData = '';

    /**
     * Is the stream paused?
     *
     * @var bool Whether the stream is paused.
     */
    protected $isPaused;

    /**
     * Whether the stream is closed.
     *
     * @var bool Whether the stream is closed.
     */
    protected $isClosed = false;

    /**
     * Maximum number of frames to buffer while paused.
     */
    private const MAX_PAUSE_BUFFER = 512;

    /**
     * The PCM pause buffer.
     *
     * @var array The PCM pause buffer.
     */
    protected $pcmPauseBuffer = [];

    /**
     * The pause buffer.
     *
     * @var array The pause buffer.
     */
    protected $opusPauseBuffer = [];

    /**
     * Constructs a stream.
     */
    public function __construct()
    {
        // empty for now
    }

    /**
     * Writes PCM audio data.
     *
     * @param string $pcm PCM audio data.
     */
    public function writePCM(string $pcm): void
    {
        if ($this->isClosed) {
            return;
        }

        if ($this->isPaused) {
            if (count($this->pcmPauseBuffer) < self::MAX_PAUSE_BUFFER) {
                $this->pcmPauseBuffer[] = $pcm;
            }

            return;
        }

        $this->pcmData .= $pcm;

        $this->emit('pcm', [$pcm]);
    }

    /**
     * Writes Opus audio data.
     *
     * @param string $opus Opus audio data.
     */
    public function writeOpus(string $opus): void
    {
        if ($this->isClosed) {
            return;
        }

        if ($this->isPaused) {
            if (count($this->opusPauseBuffer) < self::MAX_PAUSE_BUFFER) {
                $this->opusPauseBuffer[] = $opus;
            }

            return;
        }

        $this->opusData .= $opus;

        $this->emit('opus', [$opus]);
    }

    /**
     * @inheritDoc
     */
    public function isReadable()
    {
        return ! $this->isPaused && ! $this->isClosed;
    }

    /**
     * @inheritDoc
     */
    public function isWritable()
    {
        return $this->isReadable();
    }

    /**
     * @inheritDoc
     */
    public function write($data)
    {
        $this->writePCM($data);
        $this->writeOpus($data);

        return true;
    }

    /**
     * @inheritDoc
     */
    public function end($data = null)
    {
        if ($this->isClosed) {
            return;
        }

        if (null !== $data) {
            $this->write($data);
        }

        $this->close();
    }

    /**
     * @inheritDoc
     */
    public function close()
    {
        if ($this->isClosed) {
            return;
        }

        $this->pause();
        $this->emit('end', []);
        $this->emit('close', []);
        $this->isClosed = true;
    }

    /**
     * @inheritDoc
     */
    public function pause()
    {
        if ($this->isClosed) {
            return;
        }

        if ($this->isPaused) {
            return;
        }

        $this->isPaused = true;
    }

    /**
     * @inheritDoc
     */
    public function resume()
    {
        if ($this->isClosed) {
            return;
        }

        if (! $this->isPaused) {
            return;
        }

        $this->isPaused = false;

        $pcmPauseBuffer = $this->pcmPauseBuffer;
        $opusPauseBuffer = $this->opusPauseBuffer;
        $this->pcmPauseBuffer = [];
        $this->opusPauseBuffer = [];

        foreach ($pcmPauseBuffer as $data) {
            $this->writePCM($data);
        }

        foreach ($opusPauseBuffer as $data) {
            $this->writeOpus($data);
        }
    }

    /**
     * @inheritDoc
     */
    public function pipe(WritableStreamInterface $dest, array $options = [])
    {
        $pipeOptions = $options;
        $pipeOptions['end'] = false;

        $this->pipePCM($dest, $pipeOptions);
        $this->pipeOpus($dest, $pipeOptions);

        $end = isset($options['end']) ? $options['end'] : true;
        if ($end && $this !== $dest) {
            $this->on('end', function () use ($dest) {
                $dest->end();
            });
        }

        return $dest;
    }

    /**
     * Pipes PCM to a destination stream.
     *
     * @param WritableStreamInterface $dest    The stream to pipe to.
     * @param array                   $options An array of options.
     */
    public function pipePCM(WritableStreamInterface $dest, array $options = []): void
    {
        if ($this->isClosed) {
            return;
        }

        $this->on('pcm', function ($data) use ($dest) {
            $feedmore = $dest->write($data);

            if (false === $feedmore) {
                $this->pause();
            }
        });

        $dest->on('drain', function () {
            $this->resume();
        });

        $end = isset($options['end']) ? $options['end'] : true;
        if ($end && $this !== $dest) {
            $this->on('end', function () use ($dest) {
                $dest->end();
            });
        }
    }

    /**
     * Pipes Opus to a destination stream.
     *
     * @param WritableStreamInterface $dest    The stream to pipe to.
     * @param array                   $options An array of options.
     */
    public function pipeOpus(WritableStreamInterface $dest, array $options = []): void
    {
        if ($this->isClosed) {
            return;
        }

        $this->on('opus', function ($data) use ($dest) {
            $feedmore = $dest->write($data);

            if (false === $feedmore) {
                $this->pause();
            }
        });

        $dest->on('drain', function () {
            $this->resume();
        });

        $end = isset($options['end']) ? $options['end'] : true;
        if ($end && $this !== $dest) {
            $this->on('end', function () use ($dest) {
                $dest->end();
            });
        }
    }
}
