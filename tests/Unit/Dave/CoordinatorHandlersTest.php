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
use Discord\Voice\Dave\GatewayCoordinator;
use Discord\Voice\Dave\GatewayCoordinatorHost;
use Discord\Voice\Dave\Runtime;
use Discord\Voice\Dave\SessionHandle;
use Discord\Voice\Dave\State;
use Discord\Voice\VoiceClient;
use Discord\WebSockets\Op;
use Discord\WebSockets\VoicePayload;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

afterEach(function (): void {
    Runtime::reset();
});

it('handleDaveMlsCommitWelcome runs the success path when processCommit reports success', function (): void {
    Runtime::configureCallbacks(
        availabilityOverride: true,
        processCommitCallback: fn () => ['failed' => false, 'ignored' => false],
    );

    $host = makeCoordHost();
    $host->state->replaceSession(new SessionHandle(new \stdClass()));

    $coord = new GatewayCoordinator($host);

    // transition_id = 7, payload follows; non-zero so completeDaveMediaTransition sends transition_ready.
    $rawPayload = pack('n', 7).'commit-welcome-bytes';
    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_COMMIT_WELCOME, $rawPayload);

    $coord->handleDaveMlsCommitWelcome($frame);

    expect($host->jsonSends)->toHaveCount(1)
        ->and($host->jsonSends[0]->op)->toBe(Op::VOICE_DAVE_TRANSITION_READY)
        ->and($host->jsonSends[0]->d['transition_id'])->toBe(7);
});

it('handleDaveMlsCommitWelcome falls through to processWelcome when commit is ignored', function (): void {
    $welcomeCalls = 0;
    Runtime::configureCallbacks(
        availabilityOverride: true,
        processCommitCallback: fn () => ['failed' => false, 'ignored' => true],
        processWelcomeCallback: function () use (&$welcomeCalls): bool {
            $welcomeCalls++;

            return true;
        },
    );

    $host = makeCoordHost();
    $host->state->replaceSession(new SessionHandle(new \stdClass()));

    $coord = new GatewayCoordinator($host);
    $rawPayload = pack('n', 12).'commit-welcome-bytes';
    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_COMMIT_WELCOME, $rawPayload);

    $coord->handleDaveMlsCommitWelcome($frame);

    // ignored commit → falls through to processWelcome → success → completeDaveMediaTransition path.
    expect($welcomeCalls)->toBe(1)
        ->and($host->jsonSends)->toHaveCount(1)
        ->and($host->jsonSends[0]->d['transition_id'])->toBe(12);
});

it('handleDaveMlsAnnounceCommitTransition follows the success path on a clean commit', function (): void {
    Runtime::configureCallbacks(
        availabilityOverride: true,
        processCommitCallback: fn () => ['failed' => false, 'ignored' => false],
    );

    $host = makeCoordHost();
    $host->state->replaceSession(new SessionHandle(new \stdClass()));

    $coord = new GatewayCoordinator($host);
    $rawPayload = pack('n', 9).'commit-bytes';
    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_ANNOUNCE_COMMIT_TRANSITION, $rawPayload);

    $coord->handleDaveMlsAnnounceCommitTransition($frame);

    expect($host->jsonSends[0]->d['transition_id'])->toBe(9);
});

it('handleDaveMlsAnnounceCommitTransition is a no-op when the commit is ignored', function (): void {
    Runtime::configureCallbacks(
        availabilityOverride: true,
        processCommitCallback: fn () => ['failed' => false, 'ignored' => true],
    );

    $host = makeCoordHost();
    $host->state->replaceSession(new SessionHandle(new \stdClass()));

    $coord = new GatewayCoordinator($host);
    $rawPayload = pack('n', 3).'data';
    $coord->handleDaveMlsAnnounceCommitTransition(new BinaryFrame(1, Op::VOICE_DAVE_MLS_ANNOUNCE_COMMIT_TRANSITION, $rawPayload));

    expect($host->jsonSends)->toBe([])
        ->and($host->binarySends)->toBe([]);
});

it('handleDaveMlsWelcome runs the success path when processWelcome returns true', function (): void {
    Runtime::configureCallbacks(
        availabilityOverride: true,
        processWelcomeCallback: fn () => true,
    );

    $host = makeCoordHost();
    $host->state->replaceSession(new SessionHandle(new \stdClass()));

    $coord = new GatewayCoordinator($host);
    $rawPayload = pack('n', 4).'welcome-bytes';
    $coord->handleDaveMlsWelcome(new BinaryFrame(1, Op::VOICE_DAVE_MLS_WELCOME, $rawPayload));

    expect($host->jsonSends)->toHaveCount(1)
        ->and($host->jsonSends[0]->d['transition_id'])->toBe(4);
});

it('handleDaveMlsExternalSender records the sender on State and triggers a key-package send', function (): void {
    $kpSent = null;
    Runtime::configureCallbacks(
        availabilityOverride: true,
        keyPackageCallback: function () use (&$kpSent): ?string {
            $kpSent = 'kp-bytes';

            return 'kp-bytes';
        },
    );

    $host = makeCoordHost();
    $host->state->replaceSession(new SessionHandle(new \stdClass()));

    $coord = new GatewayCoordinator($host);
    $coord->handleDaveMlsExternalSender(new BinaryFrame(1, Op::VOICE_DAVE_MLS_EXTERNAL_SENDER, 'sender-payload'));

    expect($host->state->externalSenderPackage)->toBe('sender-payload')
        ->and($host->binarySends)->toHaveCount(1)
        ->and($host->binarySends[0][0])->toBe(Op::VOICE_DAVE_MLS_KEY_PACKAGE)
        ->and($host->binarySends[0][1])->toBe('kp-bytes');
});

// Helpers

function makeCoordHost(): TestCoordHost
{
    return new TestCoordHost();
}

class TestCoordHost implements GatewayCoordinatorHost
{
    public State $state;
    public LoggerInterface $logger;

    /** @var array<int, VoicePayload> */
    public array $jsonSends = [];

    /** @var array<int, array{0: int, 1: string}> */
    public array $binarySends = [];

    public bool $closed = false;

    public function __construct()
    {
        $this->state = new State();
        $this->logger = new NullLogger();
    }

    public function sendDaveBinary(int $opcode, string $payload = ''): void
    {
        $this->binarySends[] = [$opcode, $payload];
    }

    public function send(VoicePayload|array $data): void
    {
        if (is_array($data)) {
            $data = VoicePayload::new($data['op'] ?? 0, $data['d'] ?? []);
        }
        $this->jsonSends[] = $data;
    }

    public function closeConnection(): void
    {
        $this->closed = true;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function getDaveState(): State
    {
        return $this->state;
    }

    public function getVoiceClient(): VoiceClient
    {
        return (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    }

    public function getMaxDaveProtocolVersion(): int
    {
        return 1;
    }
}
