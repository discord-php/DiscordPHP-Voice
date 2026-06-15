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

namespace Discord\Voice\Dave;

use Discord\WebSockets\Op;
use Discord\WebSockets\VoicePayload;

/**
 * Handles all DAVE E2EE gateway protocol logic on behalf of the voice WebSocket.
 *
 * This class owns every DAVE opcode handler and helper that was previously
 * embedded in {@see \Discord\Voice\Client\WS}.  WS keeps thin proxy methods so
 * the opcode dispatch table remains unchanged; all real work happens here.
 *
 * @since 10.20.0
 */
class GatewayCoordinator
{
    public function __construct(private readonly GatewayCoordinatorHost $host)
    {
    }

    // -------------------------------------------------------------------------
    // DAVE opcode handlers — called via WS proxy methods
    // -------------------------------------------------------------------------

    public function handleDavePrepareTransition(mixed $data): void
    {
        $transitionId = (int) ($data->d['transition_id'] ?? 0);
        $this->host->getLogger()->debug('DAVE: prepare transition', ['transition_id' => $transitionId]);
        $protocolVersion = $this->resolveDaveProtocolVersion((int) ($data->d['protocol_version'] ?? 0));

        $this->prepareDaveMediaTransition($transitionId, $protocolVersion);
        $this->completeDaveMediaTransition($transitionId);
    }

    public function handleDaveExecuteTransition(mixed $data): void
    {
        $transitionId = (int) ($data->d['transition_id'] ?? 0);
        $this->host->getLogger()->debug('DAVE: execute transition', ['transition_id' => $transitionId]);

        $this->executeDaveMediaTransition($transitionId);
    }

    public function handleDaveTransitionReady(mixed $data): void
    {
        $transitionId = (int) ($data->d['transition_id'] ?? 0);
        $this->host->getLogger()->debug('DAVE: transition ready', ['transition_id' => $transitionId]);

        $daveState = $this->host->getDaveState();
        if ($daveState->pendingTransitionId !== $transitionId) {
            return;
        }

        $this->applySelfDaveEncryptor(
            $daveState->pendingProtocolVersion ?? $daveState->protocolVersion
        );
        $daveState->executeTransition($transitionId);
    }

    public function handleDavePrepareEpoch(mixed $data): void
    {
        $epoch = (int) ($data->d['epoch'] ?? 0);
        $this->host->getLogger()->debug('DAVE: prepare epoch', ['epoch' => $epoch]);
        $protocolVersion = $this->resolveDaveProtocolVersion($this->extractProtocolVersion($data->d));
        $transitionId = isset($data->d['transition_id']) ? (int) $data->d['transition_id'] : null;

        $daveState = $this->host->getDaveState();
        $daveState->prepareEpoch($epoch);
        if ($transitionId !== null) {
            $daveState->prepareTransition($transitionId, $protocolVersion);
        } else {
            $daveState->latestPreparedTransitionVersion = $protocolVersion;
        }

        if ($protocolVersion <= 0) {
            return;
        }

        if (! $this->initializeDaveRuntimeState($protocolVersion, $epoch === 1)) {
            $this->host->getLogger()->error('DAVE session initialization failed; closing voice connection (fail-closed).', [
                'protocol_version' => $protocolVersion,
                'epoch' => $epoch,
            ]);
            $this->host->closeConnection();

            return;
        }

        if ($epoch === 1) {
            $this->sendDaveKeyPackage();
        }
    }

    public function handleDaveMlsExternalSender(mixed $data): void
    {
        $this->host->getLogger()->debug('DAVE: MLS external sender');
        if ($data instanceof BinaryFrame) {
            $daveState = $this->host->getDaveState();
            $daveState->recordExternalSender($data->payload);

            if ($daveState->session !== null) {
                if (! Runtime::setExternalSender($daveState->session, $data->payload)) {
                    $this->host->getLogger()->error('DAVE: failed to set MLS external sender');
                }

                $this->sendDaveKeyPackage();
            }
        }
    }

    /**
     * Handle an inbound opcode 26 (VOICE_DAVE_MLS_KEY_PACKAGE) frame from the gateway.
     *
     * Opcode 26 is primarily client→server: we send our own key package to the gateway
     * via {@see sendDaveKeyPackage()} during the DAVE epoch-1 setup.  The gateway may
     * also forward a remote member's key package back to us as an informational notice —
     * that is what this handler receives.
     *
     * The gateway (server) is responsible for aggregating all key packages and driving
     * the subsequent proposal/commit flow.  We passively receive the forwarded package;
     * no action is required on the client side.
     *
     * Per the Discord DAVE spec: "Key packages are only used one time" — each time we
     * need to join or rejoin a session we generate and send a fresh key package.
     */
    public function handleDaveMlsKeyPackage(mixed $data): void
    {
        $this->host->getLogger()->debug('DAVE: MLS key package received');

        if (! ($data instanceof BinaryFrame)) {
            return;
        }

        // Server handles proposal aggregation; we passively receive forwarded key packages.
    }

    public function handleDaveMlsProposals(mixed $data): void
    {
        $this->host->getLogger()->debug('DAVE: MLS proposals');

        if (! ($data instanceof BinaryFrame)) {
            return;
        }

        $daveState = $this->host->getDaveState();
        $payload = null;
        if ($daveState->session !== null) {
            $payload = Runtime::buildMlsCommitWelcomeWithSession(
                $daveState->session,
                $data->payload,
                $daveState->recognizedUsersIncludingSelf()
            );
        }

        if ($payload === null) {
            $payload = Runtime::buildMlsCommitWelcome($data->payload, $daveState->protocolVersion);
        }

        if ($payload === null) {
            // Per spec, INVALID_COMMIT_WELCOME (op 31) is only for unprocessable
            // op 29/30 messages, not for proposal failures.  Sending it here causes
            // Discord to re-send the same stale proposals indefinitely.  Instead we
            // count consecutive failures and disconnect after 3 so the voice client
            // reconnects and obtains a fresh DAVE epoch from the gateway.
            $daveState->incrementProposalFailures();
            $this->host->getLogger()->warning('DAVE: failed to build MLS commit from proposals', [
                'consecutive_failures' => $daveState->proposalFailureCount,
                'recognized_users' => $daveState->recognizedUsersIncludingSelf(),
            ]);

            if ($daveState->proposalFailureCount >= 3) {
                $this->host->getLogger()->error(
                    'DAVE: too many consecutive proposal failures; disconnecting to force fresh epoch'
                );
                $this->host->closeConnection();
            }

            return;
        }

        $daveState->resetProposalFailures();
        $this->host->sendDaveBinary(Op::VOICE_DAVE_MLS_COMMIT_WELCOME, $payload);
    }

    public function handleDaveMlsCommitWelcome(mixed $data): void
    {
        $this->host->getLogger()->debug('DAVE: MLS commit welcome');

        $daveState = $this->host->getDaveState();
        if (! ($data instanceof BinaryFrame) || $daveState->session === null) {
            return;
        }

        $tp = TransitionPayload::parse($data->payload);
        $transitionId = $tp !== null ? $tp->transitionId : 0;
        $commitWelcome = $tp !== null ? $tp->payload : '';

        $result = Runtime::processCommit($daveState->session, $commitWelcome);
        if ($result !== null && ! ($result['failed'] ?? true) && ! ($result['ignored'] ?? false)) {
            $this->prepareDaveMediaTransition(
                $transitionId,
                $daveState->pendingProtocolVersion ?? $daveState->protocolVersion
            );
            $this->completeDaveMediaTransition($transitionId);

            return;
        }

        $joinedGroup = Runtime::processWelcome(
            $daveState->session,
            $commitWelcome,
            $daveState->recognizedUsersIncludingSelf()
        );

        if ($joinedGroup) {
            $this->prepareDaveMediaTransition(
                $transitionId,
                $daveState->pendingProtocolVersion ?? $daveState->protocolVersion
            );
            $this->completeDaveMediaTransition($transitionId);

            return;
        }

        $this->handleInvalidDaveTransition($transitionId);
    }

    public function handleDaveMlsAnnounceCommitTransition(mixed $data): void
    {
        $this->host->getLogger()->debug('DAVE: MLS announce commit transition');

        $daveState = $this->host->getDaveState();
        if (! ($data instanceof BinaryFrame) || $daveState->session === null) {
            return;
        }

        $tp = TransitionPayload::parse($data->payload);
        $transitionId = $tp !== null ? $tp->transitionId : 0;
        $commit = $tp !== null ? $tp->payload : '';
        $result = Runtime::processCommit($daveState->session, $commit);

        if ($result === null || ($result['failed'] ?? true)) {
            $this->handleInvalidDaveTransition($transitionId, true);

            return;
        }

        if ($result['ignored'] ?? false) {
            return;
        }

        $this->prepareDaveMediaTransition(
            $transitionId,
            $daveState->pendingProtocolVersion ?? $daveState->protocolVersion
        );
        $this->completeDaveMediaTransition($transitionId);
    }

    public function handleDaveMlsWelcome(mixed $data): void
    {
        $this->host->getLogger()->debug('DAVE: MLS welcome');

        $daveState = $this->host->getDaveState();
        if (! ($data instanceof BinaryFrame) || $daveState->session === null) {
            return;
        }

        $tp = TransitionPayload::parse($data->payload);
        $transitionId = $tp !== null ? $tp->transitionId : 0;
        $welcome = $tp !== null ? $tp->payload : '';
        $joinedGroup = Runtime::processWelcome(
            $daveState->session,
            $welcome,
            $daveState->recognizedUsersIncludingSelf()
        );

        if (! $joinedGroup) {
            $this->handleInvalidDaveTransition($transitionId, true);

            return;
        }

        $this->prepareDaveMediaTransition(
            $transitionId,
            $daveState->pendingProtocolVersion ?? $daveState->protocolVersion
        );
        $this->completeDaveMediaTransition($transitionId);
    }

    public function handleDaveMlsInvalidCommitWelcome(mixed $data): void
    {
        $this->host->getLogger()->warning('DAVE: invalid MLS commit/welcome; recovering state.');
        if ($data instanceof BinaryFrame) {
            $tp = TransitionPayload::parse($data->payload);
            $transitionId = $tp !== null ? $tp->transitionId : 0;
        } else {
            $transitionId = (int) ($data->d['transition_id'] ?? 0);
        }
        $this->handleInvalidDaveTransition($transitionId, true);
    }

    // -------------------------------------------------------------------------
    // Helper methods — public so WS proxy methods can delegate here
    // -------------------------------------------------------------------------

    /**
     * Extracts the DAVE protocol version from a gateway payload data array.
     *
     * @param array<mixed> $data
     */
    public function extractProtocolVersion(array $data): int
    {
        return (int) ($data['dave_protocol_version'] ?? $data['protocol_version'] ?? 0);
    }

    /**
     * Clamps the offered protocol version to the maximum supported by this runtime.
     */
    public function resolveDaveProtocolVersion(int $protocolVersion): int
    {
        if ($protocolVersion <= 0) {
            return 0;
        }

        $max = $this->host->getMaxDaveProtocolVersion();
        // libdave availability is enforced in WS::__construct(); this point is always reached with a loaded runtime.
        if ($protocolVersion > $max) {
            $this->host->getLogger()->warning("DAVE: server offered protocol version {$protocolVersion} but max supported is {$max}; clamping.");
        }

        return min($protocolVersion, $max);
    }

    /**
     * Ensures a DAVE MLS session and encryptor are ready for the given protocol version.
     *
     * Returns true on success, false if initialization failed (caller should close the connection).
     */
    public function initializeDaveRuntimeState(int $protocolVersion, bool $resetState = false): bool
    {
        $daveState = $this->host->getDaveState();

        if ($protocolVersion <= 0) {
            $daveState->resetProtocolState();
            $daveState->setProtocolVersion(0);

            return true;
        }

        if ($daveState->selfUserId === null || $daveState->groupId === null) {
            $this->host->getLogger()->warning('DAVE: cannot initialize without voice identity.');

            return false;
        }

        if ($resetState) {
            $daveState->resetProtocolState();
        }

        $sessionCreated = false;
        if ($daveState->session === null) {
            $session = Runtime::createSession();
            if ($session === null) {
                $this->host->getLogger()->warning('Failed to create DAVE MLS session.', ['error' => Runtime::getLastLoadError()]);

                return false;
            }

            $daveState->replaceSession($session);
            $sessionCreated = true;
        }

        if ($daveState->encryptor === null) {
            $encryptor = Runtime::createEncryptor();
            if ($encryptor === null) {
                $this->host->getLogger()->warning('Failed to create DAVE encryptor.', ['error' => Runtime::getLastLoadError()]);

                return false;
            }

            $daveState->replaceEncryptor($encryptor);
        }

        if (! Runtime::configureEncryptorPassthrough($daveState->encryptor, false)) {
            $this->host->getLogger()->error('Failed to configure encryptor passthrough');
        }

        if ($daveState->externalSenderPackage !== null) {
            if (! Runtime::setExternalSender($daveState->session, $daveState->externalSenderPackage)) {
                $this->host->getLogger()->error('Failed to set DAVE MLS external sender from state');
            }
        }

        $shouldInitSession = $resetState || $sessionCreated || $daveState->epoch === 1;
        if ($shouldInitSession && ! Runtime::initializeSession(
            $daveState->session,
            $protocolVersion,
            $daveState->groupId,
            $daveState->selfUserId
        )) {
            $this->host->getLogger()->warning('Failed to initialize DAVE MLS session.', ['error' => Runtime::getLastLoadError()]);

            return false;
        }

        $daveState->prepareProtocolVersion($protocolVersion);

        return true;
    }

    /**
     * Sends the local MLS key package to the gateway.
     */
    public function sendDaveKeyPackage(): void
    {
        $daveState = $this->host->getDaveState();

        if ($daveState->session === null || $daveState->keyPackageSent) {
            return;
        }

        $keyPackage = Runtime::getMarshalledKeyPackage($daveState->session);
        if ($keyPackage === null) {
            $this->host->getLogger()->warning('Failed to generate DAVE MLS key package.', ['error' => Runtime::getLastLoadError()]);

            return;
        }

        $this->host->sendDaveBinary(Op::VOICE_DAVE_MLS_KEY_PACKAGE, $keyPackage);
        $daveState->markKeyPackageSent();
    }

    // -------------------------------------------------------------------------
    // Private DAVE helpers
    // -------------------------------------------------------------------------

    private function executeDaveMediaTransition(int $transitionId): void
    {
        $daveState = $this->host->getDaveState();

        if ($daveState->pendingTransitionId !== $transitionId) {
            return;
        }

        $protocolVersion = $daveState->pendingProtocolVersion ?? $daveState->protocolVersion;

        if ($protocolVersion <= 0) {
            $this->host->getLogger()->warning("DAVE: protocol downgrading from v{$daveState->protocolVersion} to v0 (passthrough); resetting session.");

            if ($daveState->session !== null) {
                Runtime::resetSession($daveState->session);
            }

            $daveState->resetProtocolState();
            $daveState->setProtocolVersion(0);

            return;
        }

        if ($daveState->session !== null) {
            if (! Runtime::setSessionProtocolVersion($daveState->session, $protocolVersion)) {
                $this->host->getLogger()->error('DAVE: failed to set session protocol version', ['protocol_version' => $protocolVersion]);
            }
        }

        $this->applySelfDaveEncryptor($protocolVersion);
        $daveState->executeTransition($transitionId);
    }

    private function prepareDaveMediaTransition(int $transitionId, int $protocolVersion): void
    {
        $daveState = $this->host->getDaveState();
        $daveState->prepareTransition($transitionId, $protocolVersion);

        foreach ($daveState->recognizedUsers() as $userId) {
            $this->prepareRemoteDaveDecryptor($userId, $protocolVersion);
        }
    }

    private function completeDaveMediaTransition(int $transitionId): void
    {
        if ($transitionId === 0) {
            $this->host->getLogger()->debug('DAVE: zero transition, executing immediately');
            $this->executeDaveMediaTransition($transitionId);

            return;
        }

        $this->sendDaveTransitionReady($transitionId);
    }

    /**
     * Prepares a DAVE decryptor for a remote user.
     *
     * Public to allow delegation from WS proxy methods and direct testing via reflection.
     */
    public function prepareRemoteDaveDecryptor(string $userId, int $protocolVersion): void
    {
        $daveState = $this->host->getDaveState();

        if ($protocolVersion <= 0 || $daveState->session === null) {
            $daveState->setDecryptor($userId, null);

            return;
        }

        $createdDecryptor = false;
        $decryptor = $daveState->getDecryptor($userId);
        if ($decryptor === null) {
            $decryptor = Runtime::createDecryptor();
            $createdDecryptor = true;
        }

        if ($decryptor === null) {
            $this->host->getLogger()->warning('Failed to create DAVE decryptor.', ['user_id' => $userId, 'error' => Runtime::getLastLoadError()]);

            return;
        }

        $keyRatchet = Runtime::getKeyRatchet($daveState->session, $userId);
        if ($keyRatchet === null) {
            if ($createdDecryptor) {
                $decryptor->destroy();
            }

            $this->host->getLogger()->warning('Failed to obtain DAVE key ratchet for remote user.', ['user_id' => $userId]);

            return;
        }

        if (! Runtime::configureDecryptorPassthrough($decryptor, true)) {
            $this->host->getLogger()->error('Failed to enable decryptor transition passthrough', ['user_id' => $userId]);
        }
        if (! Runtime::configureDecryptorKeyRatchet($decryptor, $keyRatchet)) {
            $this->host->getLogger()->error('Failed to configure decryptor key ratchet', ['user_id' => $userId]);
        }
        if (! Runtime::configureDecryptorPassthrough($decryptor, false)) {
            $this->host->getLogger()->error('Failed to configure decryptor passthrough', ['user_id' => $userId]);
        }

        $daveState->setKeyRatchet($userId, $keyRatchet);
        $daveState->setDecryptor($userId, $decryptor);
    }

    /** Public to allow delegation from WS proxy methods and direct testing via reflection. */
    public function applySelfDaveEncryptor(int $protocolVersion): void
    {
        $daveState = $this->host->getDaveState();

        if ($daveState->encryptor === null) {
            return;
        }

        if ($protocolVersion <= 0) {
            if (! Runtime::configureEncryptorPassthrough($daveState->encryptor, true)) {
                $this->host->getLogger()->error('Failed to configure encryptor passthrough');
            }

            return;
        }

        if ($daveState->session === null || $daveState->selfUserId === null) {
            return;
        }

        $keyRatchet = Runtime::getKeyRatchet($daveState->session, $daveState->selfUserId);
        if ($keyRatchet === null) {
            $this->host->getLogger()->warning('Failed to obtain DAVE key ratchet for local sender.', ['user_id' => $daveState->selfUserId]);

            return;
        }

        if (! Runtime::configureEncryptorPassthrough($daveState->encryptor, false)) {
            $this->host->getLogger()->error('Failed to configure encryptor passthrough');
        }
        if (! Runtime::configureEncryptorKeyRatchet($daveState->encryptor, $keyRatchet)) {
            $this->host->getLogger()->error('Failed to configure encryptor key ratchet');
        }
        $daveState->setSelfKeyRatchet($keyRatchet);
    }

    private function sendDaveTransitionReady(int $transitionId): void
    {
        $this->host->getLogger()->debug('sending DAVE transition ready', [
            'transition_id' => $transitionId,
        ]);

        $this->host->send(VoicePayload::new(
            Op::VOICE_DAVE_TRANSITION_READY,
            ['transition_id' => $transitionId],
        ));
    }

    private function sendDaveInvalidCommitWelcome(): void
    {
        $this->host->sendDaveBinary(Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME);
    }

    /** Public to allow delegation from WS proxy methods and direct testing via reflection. */
    public function handleInvalidDaveTransition(int $transitionId, bool $regenerateKeyPackage = false): void
    {
        $this->host->getLogger()->warning('DAVE transition failed; requesting re-add to MLS group.', ['transition_id' => $transitionId]);
        $this->sendDaveInvalidCommitWelcome();

        $daveState = $this->host->getDaveState();
        $protocolVersion = $daveState->pendingProtocolVersion ?? $daveState->protocolVersion;
        if ($protocolVersion <= 0) {
            $daveState->resetProtocolState();
            $daveState->setProtocolVersion(0);

            return;
        }

        if ($regenerateKeyPackage) {
            if (! $this->initializeDaveRuntimeState($protocolVersion, true)) {
                return;
            }

            $this->sendDaveKeyPackage();
        }
    }
}
