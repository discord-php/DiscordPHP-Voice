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

// Usage: BOT_TOKEN=<token> CHANNEL_ID=<id> php queue.php

use Discord\Discord;
use Discord\Parts\Channel\Channel;
use Discord\Voice\Exceptions\Channels\AudioAlreadyPlayingException;
use Discord\Voice\Exceptions\Channels\CantSpeakInChannelException;
use Discord\Voice\Exceptions\Channels\EnterChannelDeniedException;
use Discord\Voice\Exceptions\ClientNotReadyException;
use Discord\Voice\Exceptions\Libraries\LibDaveNotFoundException;
use Discord\Voice\VoiceClient;

require __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// Pattern 1: Sequential queue — play tracks one after another using recursion.
//
// Each call plays the first track, then registers a one-shot 'end' listener
// that calls playQueue() again with the remaining tracks.
// ---------------------------------------------------------------------------
function playQueue(VoiceClient $vc, array $tracks): void
{
    if (empty($tracks)) {
        echo 'Queue finished.' . PHP_EOL;
        $vc->disconnect();

        return;
    }

    $track = array_shift($tracks);
    echo 'Now playing: ' . $track . PHP_EOL;

    $vc->playFile($track)->then(
        null,
        function (\Throwable $e): void {
            echo 'Playback error: ' . $e->getMessage() . PHP_EOL;
        }
    );

    // The third argument `true` makes this a one-shot listener — it fires once
    // for the current track and is automatically removed afterwards.
    $vc->on('end', function () use ($vc, $tracks): void {
        playQueue($vc, $tracks);
    }, true);
}

// ---------------------------------------------------------------------------
// Pattern 2: Skip the current track and immediately play a replacement.
//
// stop() is synchronous and void — it inserts silence frames and emits 'end'.
// Register a one-shot 'end' listener *before* calling stop() so the new track
// starts as soon as playback fully drains.
// ---------------------------------------------------------------------------
function skipTo(VoiceClient $vc, string $nextTrack): void
{
    if ($vc->speaking === VoiceClient::NOT_SPEAKING) {
        // Nothing is playing; start the new track directly.
        startTrack($vc, $nextTrack);

        return;
    }

    echo 'Skipping current track...' . PHP_EOL;

    // Listen for the 'end' event that stop() will trigger, then play the
    // replacement track. The one-shot flag prevents stale listeners piling up.
    $vc->on('end', function () use ($vc, $nextTrack): void {
        startTrack($vc, $nextTrack);
    }, true);

    $vc->stop();
}

function startTrack(VoiceClient $vc, string $track): void
{
    echo 'Playing: ' . $track . PHP_EOL;

    $vc->playFile($track)->then(
        null,
        function (\Throwable $e): void {
            echo 'Playback error: ' . $e->getMessage() . PHP_EOL;
        }
    );
}

// ---------------------------------------------------------------------------
// Pattern 3: Error handling — AudioAlreadyPlayingException / ClientNotReadyException.
//
// playFile() rejects its promise when audio is already playing or the client
// is not yet ready. Catch these explicitly to give the user actionable feedback.
// ---------------------------------------------------------------------------
function safePlay(VoiceClient $vc, string $track): void
{
    $vc->playFile($track)->then(
        function (): void {
            echo 'Playback started.' . PHP_EOL;
        },
        function (\Throwable $e): void {
            if ($e instanceof AudioAlreadyPlayingException) {
                // Audio is already streaming. Call stop() first, or queue the
                // track using the pattern above instead of calling playFile() directly.
                echo 'Already playing — stop the current track before starting a new one.' . PHP_EOL;
            } elseif ($e instanceof ClientNotReadyException) {
                // The VoiceClient has not yet completed its handshake. Wait for the
                // 'ready' event emitted by VoiceClient before calling playFile().
                echo 'Client not ready yet — wait for the ready event.' . PHP_EOL;
            } else {
                echo 'Unexpected playback error: ' . $e->getMessage() . PHP_EOL;
            }
        }
    );
}

// ---------------------------------------------------------------------------
// Bootstrap — join channel then demonstrate all three patterns.
// ---------------------------------------------------------------------------

$discord = new Discord([
    'token' => getenv('BOT_TOKEN'),
]);

$discord->on('ready', function (Discord $discord): void {
    echo 'Bot is ready.' . PHP_EOL;

    $channel = $discord->getChannel(getenv('CHANNEL_ID'));

    if ($channel === null || $channel->type !== Channel::TYPE_VOICE) {
        echo 'Channel not found or not a voice channel.' . PHP_EOL;
        $discord->close();

        return;
    }

    $discord->voice->join($channel)->then(
        function (VoiceClient $vc) use ($discord): void {
            echo 'Joined voice channel, waiting for ready...' . PHP_EOL;

            $vc->on('ready', function () use ($vc, $discord): void {
                echo 'VoiceClient ready.' . PHP_EOL;

                $tracks = [
                    __DIR__ . '/audio1.mp3',
                    __DIR__ . '/audio2.mp3',
                    __DIR__ . '/audio3.mp3',
                ];

                // --- Pattern 1: play the queue sequentially ---
                playQueue($vc, $tracks);

                // --- Pattern 2: skip to a different track after 5 seconds ---
                $discord->getLoop()->addTimer(5, function () use ($vc): void {
                    skipTo($vc, __DIR__ . '/interruption.mp3');
                });

                // --- Pattern 3: attempt to play while already playing (demonstrates
                //     AudioAlreadyPlayingException handling) after 6 seconds ---
                $discord->getLoop()->addTimer(6, function () use ($vc): void {
                    safePlay($vc, __DIR__ . '/audio4.mp3');
                });
            });

            $vc->on('error', function (\Throwable $e): void {
                echo 'Voice error: ' . $e->getMessage() . PHP_EOL;
            });
        },
        function (\Throwable $e): void {
            if ($e instanceof LibDaveNotFoundException) {
                echo 'libdave is required for voice (DAVE E2EE). Run ./scripts/setup-libdave.sh.' . PHP_EOL;
            } elseif ($e instanceof EnterChannelDeniedException) {
                echo 'Bot does not have permission to join that channel.' . PHP_EOL;
            } elseif ($e instanceof CantSpeakInChannelException) {
                echo 'Bot does not have permission to speak in that channel.' . PHP_EOL;
            } else {
                echo 'Failed to join: ' . $e->getMessage() . PHP_EOL;
            }
        }
    );
});

$discord->run();
