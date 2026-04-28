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

// Usage: BOT_TOKEN=<token> CHANNEL_ID=<id> RECORD_SECONDS=10 php record.php
// For WAV output: add RecordingFormat::WAV and an output path callback — see inline comment below.

use Discord\Discord;
use Discord\Parts\Channel\Channel;
use Discord\Voice\Exceptions\Channels\EnterChannelDeniedException;
use Discord\Voice\Exceptions\Libraries\LibDaveNotFoundException;
use Discord\Voice\VoiceClient;

require __DIR__ . '/../vendor/autoload.php';

$discord = new Discord([
    'token' => getenv('BOT_TOKEN'),
]);

$discord->on('init', function (Discord $discord): void {
    echo 'Bot is ready.' . PHP_EOL;

    $channel = $discord->getChannel(getenv('CHANNEL_ID'));

    if ($channel === null || $channel->type !== Channel::TYPE_VOICE) {
        echo 'Channel not found or not a voice channel.' . PHP_EOL;
        $discord->close();

        return;
    }

    $discord->voice->joinChannel($channel)->then(
        function (VoiceClient $vc) use ($discord): void {
            $vc->on('ready', function () use ($vc, $discord): void {
                echo 'VoiceClient ready, starting recording...' . PHP_EOL;

                // Begin capturing audio from all speaking users.
                $vc->record();

                // channel-pcm fires for every decoded PCM frame received from a user.
                // $pcm is raw 16-bit stereo 48 kHz PCM bytes.
                $vc->on('channel-pcm', function (string $pcm): void {
                    // Handle the PCM data — write to a file, pipe into an encoder, etc.
                    echo sprintf('Received %d bytes of PCM' . PHP_EOL, strlen($pcm));
                });

                // channel-opus fires with the raw Opus packet before decoding.
                // Use this if you want to forward Opus directly without re-encoding.
                // $vc->on('channel-opus', function (string $userId, string $opus): void { ... });

                // --- Alternative: automatic per-user WAV files ---
                // use Discord\Voice\Recording\RecordingFormat;
                //
                // $vc->record(
                //     RecordingFormat::WAV,
                //     fn(string $userId) => __DIR__ . "/recording_{$userId}.wav"
                // );
                // // channel-pcm still fires; stopRecording() finalizes the WAV files automatically.

                $recordSeconds = (int) (getenv('RECORD_SECONDS') ?: 10);
                echo sprintf('Recording for %d seconds...' . PHP_EOL, $recordSeconds);

                // Stop recording after the configured duration.
                $discord->getLoop()->addTimer($recordSeconds, function () use ($vc): void {
                    $vc->stopRecording();
                    echo 'Recording stopped.' . PHP_EOL;
                    $vc->disconnect();
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
            } else {
                echo 'Failed to join: ' . $e->getMessage() . PHP_EOL;
            }
        }
    );
});

$discord->run();
