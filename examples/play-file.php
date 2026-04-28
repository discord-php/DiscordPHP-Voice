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

// Usage: BOT_TOKEN=<token> CHANNEL_ID=<id> php play-file.php

// All voice library exceptions implement \Discord\Voice\Exceptions\VoiceException,
// so you can catch them all with a single handler — see the catch block below.

use Discord\Discord;
use Discord\Parts\Channel\Channel;
use Discord\Voice\Exceptions\Channels\CantSpeakInChannelException;
use Discord\Voice\Exceptions\Channels\EnterChannelDeniedException;
use Discord\Voice\Exceptions\Libraries\LibDaveNotFoundException;
use Discord\Voice\Exceptions\VoiceException;
use Discord\Voice\VoiceClient;
use Discord\WebSockets\Event;

require __DIR__ . '/../vendor/autoload.php';

$discord = new Discord([
    'token' => getenv('BOT_TOKEN'),
]);

$discord->on('init', function (Discord $discord): void {
    echo 'Bot is ready.' . PHP_EOL;

    // Resolve the voice channel. Adjust guild/channel lookup as needed.
    $channel = $discord->getChannel(getenv('CHANNEL_ID'));

    if ($channel === null || $channel->type !== Channel::TYPE_VOICE) {
        echo 'Channel not found or not a voice channel.' . PHP_EOL;
        $discord->close();

        return;
    }

    // Join the voice channel. Returns a promise that resolves with a VoiceClient.
    $discord->voice->joinChannel($channel)->then(
        function (VoiceClient $vc): void {
            echo 'Joined voice channel, waiting for ready...' . PHP_EOL;

            $vc->on('ready', function () use ($vc): void {
                echo 'VoiceClient ready, playing file...' . PHP_EOL;

                // Play a local file or a URL (ffmpeg must be installed).
                $vc->playFile(__DIR__ . '/audio.mp3')->then(
                    function (): void {
                        echo 'Playback started.' . PHP_EOL;
                    },
                    function (\Throwable $e): void {
                        echo 'Failed to start playback: ' . $e->getMessage() . PHP_EOL;
                    }
                );
            });

            // Emitted when the current track finishes playing.
            $vc->on('end', function () use ($vc): void {
                echo 'Playback ended.' . PHP_EOL;
                $vc->disconnect();
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
            } elseif ($e instanceof VoiceException) {
                // Catches any other voice library error not matched above
                echo 'Voice error: ' . $e->getMessage() . PHP_EOL;
            } else {
                echo 'Failed to join: ' . $e->getMessage() . PHP_EOL;
            }
        }
    );
});

$discord->run();
