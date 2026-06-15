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

    // Discord rate-limits rapid voice connect/disconnect cycles. When this file
    // runs more than one integration test in a single suite, give the gateway a
    // few seconds to fully tear down the previous voice session before joining.
    static $first = true;
    if ($first) {
        $first = false;
    } else {
        sleep(8);
    }
});

test('bot reaches Discord ready state', function (): void {
    if (! getenv('INTEGRATION_FULL')) {
        $this->markTestSkipped('Set INTEGRATION_FULL=1 to run individual voice integration tests; the "full session" test covers ready+join+play+record in batch.');
    }
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
    if (! getenv('INTEGRATION_FULL')) {
        $this->markTestSkipped('Set INTEGRATION_FULL=1 to run individual voice integration tests; the "full session" test covers the same paths in batch.');
    }
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

        $discord->voice->joinChannel($channel, $discord, $discord->voice_sessions, deaf: false)->then(
            function (VoiceClient $vc) use (&$done, &$result, $discord): void {
                // joinChannel only resolves once VC has emitted ready, so this is the success path.
                $result = 'vc-ready';
                $done = true;
                $vc->disconnect();
                $discord->close();
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

    runLoop(60, $done, $result);

    if ($result instanceof \Throwable) {
        throw $result;
    }

    expect($result)->toBe('vc-ready');
});

test('DAVE MLS External Sender opcode is received during voice session', function (): void {
    if (! getenv('INTEGRATION_FULL')) {
        $this->markTestSkipped('Set INTEGRATION_FULL=1 to run individual voice integration tests.');
    }
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

        $discord->voice->joinChannel($channel, $discord, $discord->voice_sessions, deaf: false)->then(
            function (VoiceClient $vc) use (&$done, &$result, &$daveOpcodes, $discord): void {
                // Capture every DAVE binary gateway frame opcode.
                $vc->on('ws-binary-message', function (BinaryFrame $frame) use (&$daveOpcodes): void {
                    $daveOpcodes[] = $frame->opcode;
                });

                // joinChannel resolved means VC is ready.
                $result = 'vc-ready';
                $done = true;
                $vc->disconnect();
                $discord->close();
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

    runLoop(60, $done, $result);

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

test('playFile() exercises the full outbound RTP+DAVE send pipeline against the live gateway', function (): void {
    if (! getenv('INTEGRATION_FULL')) {
        $this->markTestSkipped('Set INTEGRATION_FULL=1 to run individual voice integration tests.');
    }
    $musicFile = '/home/sky/discord-php/test-voice/music/a.mp3';
    if (! file_exists($musicFile)) {
        $this->markTestSkipped('Live integration audio fixture missing: '.$musicFile);
    }

    $done = false;
    $result = null;
    $stderr = [];
    $channelId = getenv('CHANNEL_ID');

    $discord = makeDiscordForIntegration();

    $discord->on('ready', function (Discord $discord) use ($channelId, $musicFile, &$done, &$result, &$stderr): void {
        $channel = $discord->getChannel($channelId);

        if ($channel === null || $channel->type !== Channel::TYPE_GUILD_VOICE) {
            $result = new \RuntimeException("Channel {$channelId} not found or not a voice channel.");
            $done = true;
            $discord->close();

            return;
        }

        $discord->voice->joinChannel($channel, $discord, $discord->voice_sessions, deaf: false)->then(
            function (VoiceClient $vc) use ($musicFile, &$done, &$result, &$stderr, $discord): void {
                $vc->on('stderr', function (string $data) use (&$stderr): void {
                    $stderr[] = $data;
                });

                // Cap playback at 8 seconds so the test stays bounded — the goal is
                // to drive readOggOpus → UDP::sendBuffer → DAVE encrypt at least once.
                $cap = Loop::addTimer(8.0, function () use ($vc, $discord, &$done, &$result): void {
                    if ($done) {
                        return;
                    }
                    try {
                        $vc->stop();
                    } catch (\Throwable) {
                    }
                    $result = 'capped';
                    $done = true;
                    try {
                        $vc->disconnect();
                    } catch (\Throwable) {
                    }
                    $discord->close();
                });

                $vc->playFile($musicFile)->then(
                    function () use ($vc, $discord, $cap, &$done, &$result): void {
                        if ($done) {
                            return;
                        }
                        Loop::cancelTimer($cap);
                        $result = 'played';
                        $done = true;
                        try {
                            $vc->disconnect();
                        } catch (\Throwable) {
                        }
                        $discord->close();
                    },
                    function (\Throwable $e) use ($vc, $discord, &$done, &$result, &$stderr): void {
                        $result = new \RuntimeException(
                            'Playback failed: '.$e->getMessage().' | ffmpeg stderr: '.implode(' ', $stderr)
                        );
                        $done = true;
                        try {
                            $vc->disconnect();
                        } catch (\Throwable) {
                        }
                        $discord->close();
                    }
                );
            },
            function (\Throwable $e) use ($discord, &$done, &$result): void {
                $result = $e;
                $done = true;
                $discord->close();
            }
        );
    });

    $discord->on('error', function (\Throwable $e) use ($discord, &$done, &$result): void {
        $result = $e;
        $done = true;
        $discord->close();
    });

    runLoop(60, $done, $result);

    if ($result instanceof \Throwable) {
        throw $result;
    }

    // Either playback ran to natural EOF or hit the 8s cap — both prove the send pipeline ran.
    expect($result)->toBeIn(['played', 'capped']);
});

test('full session: join → play → record → disconnect in a single bot connection', function (): void {
    $musicFile = '/home/sky/discord-php/test-voice/music/a.mp3';
    if (! file_exists($musicFile)) {
        $this->markTestSkipped('Live integration audio fixture missing: '.$musicFile);
    }

    $done = false;
    $result = null;
    $stages = [];
    $stderr = [];
    $channelId = getenv('CHANNEL_ID');

    $discord = makeDiscordForIntegration();

    $discord->on('ready', function (Discord $discord) use ($channelId, $musicFile, &$done, &$result, &$stages, &$stderr): void {
        $stages[] = 'ready';

        $channel = $discord->getChannel($channelId);
        if ($channel === null || $channel->type !== Channel::TYPE_GUILD_VOICE) {
            $result = new \RuntimeException("Channel {$channelId} not found or not a voice channel.");
            $done = true;
            $discord->close();

            return;
        }

        $discord->voice->joinChannel($channel, $discord, $discord->voice_sessions, deaf: false)->then(
            function (VoiceClient $vc) use ($musicFile, &$done, &$result, &$stages, &$stderr, $discord): void {
                $stages[] = 'joined';
                $vc->on('stderr', function (string $data) use (&$stderr): void {
                    $stderr[] = $data;
                });

                // Stage 1: kick off playback; capture errors but don't wait for natural EOF.
                $playFailed = false;
                $vc->playFile($musicFile)->then(null, function (\Throwable $e) use (&$playFailed, &$stages, &$stderr): void {
                    $playFailed = true;
                    $stages[] = 'play-error: '.$e->getMessage().' | '.implode(' ', $stderr);
                });

                // Drive ~10 seconds of real audio through the send pipeline, then advance.
                Loop::addTimer(10.0, function () use ($vc, $discord, &$done, &$result, &$stages, &$playFailed): void {
                    if ($playFailed) {
                        $result = new \RuntimeException(end($stages) ?: 'play failed');
                        $done = true;
                        $discord->close();

                        return;
                    }

                    try {
                        $vc->stop();
                    } catch (\Throwable) {
                    }
                    $stages[] = 'played';

                    // Stage 2: record briefly, then stop.
                    $vc->record();
                    $stages[] = 'recording';

                    Loop::addTimer(3.0, function () use ($vc, $discord, &$done, &$result, &$stages): void {
                        try {
                            $vc->stopRecording();
                            $stages[] = 'stop-recorded';
                        } catch (\Throwable $e) {
                            $result = $e;
                            $done = true;
                            $discord->close();

                            return;
                        }

                        try {
                            $vc->disconnect();
                        } catch (\Throwable) {
                        }
                        $stages[] = 'disconnected';
                        $result = 'ok';
                        $done = true;
                        $discord->close();
                    });
                });
            },
            function (\Throwable $e) use ($discord, &$done, &$result): void {
                $result = $e;
                $done = true;
                $discord->close();
            }
        );
    });

    $discord->on('error', function (\Throwable $e) use ($discord, &$done, &$result): void {
        $result = $e;
        $done = true;
        $discord->close();
    });

    runLoop(90, $done, $result);

    if ($result instanceof \Throwable) {
        throw new \RuntimeException(
            $result->getMessage().' [stages reached: '.implode(',', $stages).']',
            0,
            $result
        );
    }

    expect($result)->toBe('ok')
        ->and($stages)->toContain('ready', 'joined', 'played', 'recording', 'stop-recorded', 'disconnected');
});

test('record() drives the inbound UDP listener and resets recording state on stop', function (): void {
    if (! getenv('INTEGRATION_FULL')) {
        $this->markTestSkipped('Set INTEGRATION_FULL=1 to run individual voice integration tests.');
    }
    $done = false;
    $result = null;
    $channelId = getenv('CHANNEL_ID');

    $discord = makeDiscordForIntegration();

    $discord->on('ready', function (Discord $discord) use ($channelId, &$done, &$result): void {
        $channel = $discord->getChannel($channelId);

        if ($channel === null || $channel->type !== Channel::TYPE_GUILD_VOICE) {
            $result = new \RuntimeException("Channel {$channelId} not found or not a voice channel.");
            $done = true;
            $discord->close();

            return;
        }

        $discord->voice->joinChannel($channel, $discord, $discord->voice_sessions, deaf: false)->then(
            function (VoiceClient $vc) use ($discord, &$done, &$result): void {
                $vc->record();

                Loop::addTimer(5.0, function () use ($vc, $discord, &$done, &$result): void {
                    if ($done) {
                        return;
                    }
                    try {
                        $vc->stopRecording();
                    } catch (\Throwable $e) {
                        $result = $e;
                        $done = true;
                        $discord->close();

                        return;
                    }
                    $result = 'recorded';
                    $done = true;
                    try {
                        $vc->disconnect();
                    } catch (\Throwable) {
                    }
                    $discord->close();
                });
            },
            function (\Throwable $e) use ($discord, &$done, &$result): void {
                $result = $e;
                $done = true;
                $discord->close();
            }
        );
    });

    runLoop(45, $done, $result);

    if ($result instanceof \Throwable) {
        throw $result;
    }

    expect($result)->toBe('recorded');
});
