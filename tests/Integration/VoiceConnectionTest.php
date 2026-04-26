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

use Discord\Discord;
use Discord\Parts\Channel\Channel;
use Discord\Voice\Dave\BinaryFrame;
use Discord\Voice\VoiceClient;
use Discord\WebSockets\Intents;
use Discord\WebSockets\OpEnum as Op;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build a minimal Discord client that writes nothing to stdout.
 */
function makeDiscordForIntegration(): Discord
{
    return new Discord([
        'token' => getenv('DISCORD_BOT_TOKEN'),
        'logger' => new NullLogger(),
        'intents' => Intents::GUILDS | Intents::GUILD_VOICE_STATES,
    ]);
}

/**
 * Run the ReactPHP loop until $done is set to true or $timeoutSec elapses.
 * Returns whatever was written into $result.
 */
function runLoop(int $timeoutSec, bool &$done, mixed &$result): void
{
    $timer = Loop::addTimer($timeoutSec, function () use (&$done, &$result): void {
        if (! $done) {
            $result = new \RuntimeException("Integration test timed out after {$timeoutSec}s");
            $done = true;
        }
    });

    // Spin the loop in 10ms ticks until $done flips.
    $tick = null;
    $tick = Loop::addPeriodicTimer(0.01, function () use (&$done, $timer, &$tick): void {
        if ($done) {
            Loop::cancelTimer($timer);
            Loop::cancelTimer($tick);
            Loop::stop();
        }
    });

    Loop::run();
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

beforeEach(function (): void {
    if (! getenv('DISCORD_BOT_TOKEN') || ! getenv('CHANNEL_ID')) {
        $this->markTestSkipped(
            'DISCORD_BOT_TOKEN and CHANNEL_ID must be set to run integration tests.'
        );
    }
});

test('bot reaches Discord ready state', function (): void {
    $done = false;
    $result = null;

    $discord = makeDiscordForIntegration();

    $discord->on('ready', function (Discord $discord) use (&$done, &$result): void {
        $result = 'ready';
        $done = true;
        $discord->close();
    });

    $discord->on('error', function (\Throwable $e) use (&$done, &$result, $discord): void {
        $result = $e;
        $done = true;
        $discord->close();
    });

    runLoop(20, $done, $result);

    if ($result instanceof \Throwable) {
        throw $result;
    }

    expect($result)->toBe('ready');
});

test('bot can join voice channel and receive VoiceClient ready', function (): void {
    $done = false;
    $result = null;
    $channelId = getenv('CHANNEL_ID');

    $discord = makeDiscordForIntegration();

    $discord->on('ready', function (Discord $discord) use ($channelId, &$done, &$result): void {
        $channel = $discord->getChannel($channelId);

        if ($channel === null || $channel->type !== Channel::TYPE_VOICE) {
            $result = new \RuntimeException("Channel {$channelId} not found or not a voice channel.");
            $done = true;
            $discord->close();

            return;
        }

        $discord->voice->joinChannel($channel, $discord, $discord->voice_sessions)->then(
            function (VoiceClient $vc) use (&$done, &$result, $discord): void {
                $vc->on('ready', function (VoiceClient $vc) use (&$done, &$result, $discord): void {
                    $result = 'vc-ready';
                    $done = true;
                    $vc->disconnect();
                    $discord->close();
                });

                $vc->on('error', function (\Throwable $e) use (&$done, &$result, $discord): void {
                    $result = $e;
                    $done = true;
                    $discord->close();
                });
            },
            function (\Throwable $e) use (&$done, &$result, $discord): void {
                $result = $e;
                $done = true;
                $discord->close();
            }
        );
    });

    $discord->on('error', function (\Throwable $e) use (&$done, &$result, $discord): void {
        $result = $e;
        $done = true;
        $discord->close();
    });

    runLoop(30, $done, $result);

    if ($result instanceof \Throwable) {
        throw $result;
    }

    expect($result)->toBe('vc-ready');
});

test('DAVE MLS External Sender opcode is received during voice session', function (): void {
    $done = false;
    $result = null;
    $daveOpcodes = [];
    $channelId = getenv('CHANNEL_ID');

    $discord = makeDiscordForIntegration();

    $discord->on('ready', function (Discord $discord) use ($channelId, &$done, &$result, &$daveOpcodes): void {
        $channel = $discord->getChannel($channelId);

        if ($channel === null || $channel->type !== Channel::TYPE_VOICE) {
            $result = new \RuntimeException("Channel {$channelId} not found or not a voice channel.");
            $done = true;
            $discord->close();

            return;
        }

        $discord->voice->joinChannel($channel, $discord, $discord->voice_sessions)->then(
            function (VoiceClient $vc) use (&$done, &$result, &$daveOpcodes, $discord): void {
                // Capture every DAVE binary gateway frame opcode.
                $vc->on('ws-binary-message', function (BinaryFrame $frame) use (&$daveOpcodes): void {
                    $daveOpcodes[] = $frame->opcode;
                });

                $vc->on('ready', function (VoiceClient $vc) use (&$done, &$result, $discord): void {
                    $result = 'vc-ready';
                    $done = true;
                    $vc->disconnect();
                    $discord->close();
                });

                $vc->on('error', function (\Throwable $e) use (&$done, &$result, $discord): void {
                    $result = $e;
                    $done = true;
                    $discord->close();
                });
            },
            function (\Throwable $e) use (&$done, &$result, $discord): void {
                $result = $e;
                $done = true;
                $discord->close();
            }
        );
    });

    $discord->on('error', function (\Throwable $e) use (&$done, &$result, $discord): void {
        $result = $e;
        $done = true;
        $discord->close();
    });

    runLoop(30, $done, $result);

    if ($result instanceof \Throwable) {
        throw $result;
    }

    expect($result)->toBe('vc-ready');

    // Opcode 25 = MLS_EXTERNAL_SENDER — Discord sends this during DAVE setup.
    expect($daveOpcodes)->toContain(
        Op::VOICE_DAVE_MLS_EXTERNAL_SENDER->value,
        'Expected DAVE MLS_EXTERNAL_SENDER (op 25) to be received during handshake'
    );
});
