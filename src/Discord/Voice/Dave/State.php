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

/**
 * Per-connection DAVE E2EE state.
 *
 * Owns the MLS session lifetime, protocol version, passthrough mode,
 * and all key-ratchet / decryptor / encryptor handles. Destruction
 * ordering matters: encryptor/decryptors must be destroyed before
 * key-ratchets, before the session (enforced by close() and __destruct()).
 *
 * State machine summary:
 *  prepareProtocolVersion() → passthroughMode=true (waiting for transition)
 *  executeTransition()      → passthroughMode=false (media E2EE active)
 *  resetProtocolState()     → passthroughMode=true (all handles freed)
 */
final class State
{
    /** @var array<string, bool> */
    private array $recognizedUserIds = [];

    /** @var array<string, DecryptorHandle> */
    private array $decryptors = [];

    /** @var array<string, KeyRatchetHandle> */
    private array $keyRatchets = [];

    private ?KeyRatchetHandle $selfKeyRatchet = null;

    public ?SessionHandle $session = null;

    public ?EncryptorHandle $encryptor = null;

    public ?string $selfUserId = null;

    public ?int $groupId = null;

    public int $protocolVersion = 0;

    public ?int $epoch = null;

    public ?int $pendingTransitionId = null;

    public ?int $pendingProtocolVersion = null;

    public ?int $latestPreparedTransitionVersion = null;

    public bool $passthroughMode = true;

    public ?string $externalSenderPackage = null;

    public ?int $lastReceivedSequence = null;

    public int $encryptFailureCount = 0;

    public int $decryptFailureCount = 0;

    /** Consecutive proposal-processing failures for the current epoch. */
    public int $proposalFailureCount = 0;

    public bool $keyPackageSent = false;

    /** Frees every native handle in the correct order (see {@see close()}). */
    public function __destruct()
    {
        $this->close();
    }

    /** Records this connection's own user id and MLS group id. */
    public function setIdentity(int|string $selfUserId, int|string|null $groupId): void
    {
        $this->selfUserId = (string) $selfUserId;
        $this->groupId = $groupId === null ? null : (int) $groupId;
    }

    /** Sets the active DAVE protocol version and derives passthrough mode (`version <= 0` means passthrough). */
    public function setProtocolVersion(int $version): void
    {
        $this->protocolVersion = $version;
        $this->passthroughMode = $version <= 0;
    }

    /** Stages a protocol version for an upcoming transition; keeps passthrough on until media E2EE was already active. */
    public function prepareProtocolVersion(int $version): void
    {
        $wasActive = $this->protocolVersion > 0 && ! $this->passthroughMode;

        $this->protocolVersion = $version;
        $this->passthroughMode = $version <= 0 || ! $wasActive;
    }

    /** Records a pending transition id and (optionally) the protocol version it will move to. */
    public function prepareTransition(int $transitionId, ?int $protocolVersion = null): void
    {
        $this->pendingTransitionId = $transitionId;
        $this->pendingProtocolVersion = $protocolVersion;

        if ($protocolVersion !== null) {
            $this->latestPreparedTransitionVersion = $protocolVersion;
        }
    }

    /** Applies the pending transition when `$transitionId` matches, promoting the staged protocol version. */
    public function executeTransition(int $transitionId): void
    {
        if ($this->pendingTransitionId !== $transitionId) {
            return;
        }

        if (isset($this->pendingProtocolVersion)) {
            $this->setProtocolVersion($this->pendingProtocolVersion);
        }

        $this->pendingTransitionId = null;
        $this->pendingProtocolVersion = null;
    }

    /** Records the MLS epoch id the next group operations belong to. */
    public function prepareEpoch(int $epoch): void
    {
        $this->epoch = $epoch;
    }

    /** Stores the last gateway sequence number seen, for `seq_ack` on heartbeats/resume. Ignores null. */
    public function recordGatewaySequence(?int $sequence): void
    {
        if ($sequence === null) {
            return;
        }

        $this->lastReceivedSequence = $sequence;
    }

    /** Stores the group's external-sender package (public key + credential). */
    public function recordExternalSender(string $senderPackage): void
    {
        $this->externalSenderPackage = $senderPackage;
    }

    /** Marks that this client has published its MLS key package. */
    public function markKeyPackageSent(): void
    {
        $this->keyPackageSent = true;
    }

    /** Bumps the consecutive media-encrypt failure counter. */
    public function incrementEncryptFailures(): void
    {
        $this->encryptFailureCount++;
    }

    /** Bumps the consecutive media-decrypt failure counter. */
    public function incrementDecryptFailures(): void
    {
        $this->decryptFailureCount++;
    }

    /** Bumps the consecutive proposal-processing failure counter for the current epoch. */
    public function incrementProposalFailures(): void
    {
        $this->proposalFailureCount++;
    }

    /** Clears the proposal-processing failure counter. */
    public function resetProposalFailures(): void
    {
        $this->proposalFailureCount = 0;
    }

    /** Swaps in a new MLS {@see SessionHandle}, destroying the previous one. */
    public function replaceSession(?SessionHandle $session): void
    {
        if ($this->session !== null && $this->session !== $session) {
            $this->session->destroy();
        }

        $this->session = $session;
    }

    /** Swaps in a new {@see EncryptorHandle}, destroying the previous one. */
    public function replaceEncryptor(?EncryptorHandle $encryptor): void
    {
        if ($this->encryptor !== null && $this->encryptor !== $encryptor) {
            $this->encryptor->destroy();
        }

        $this->encryptor = $encryptor;
    }

    /** Sets (or, with null, removes and destroys) the per-user {@see DecryptorHandle} for `$userId`. */
    public function setDecryptor(int|string $userId, ?DecryptorHandle $decryptor): void
    {
        $userId = (string) $userId;

        if (isset($this->decryptors[$userId]) && $this->decryptors[$userId] !== $decryptor) {
            $this->decryptors[$userId]->destroy();
        }

        if ($decryptor === null) {
            unset($this->decryptors[$userId]);

            return;
        }

        $this->decryptors[$userId] = $decryptor;
    }

    /** The {@see DecryptorHandle} for `$userId`, or null. */
    public function getDecryptor(int|string $userId): ?DecryptorHandle
    {
        return $this->decryptors[(string) $userId] ?? null;
    }

    /**
     * @return array<string, DecryptorHandle>
     */
    public function getAllDecryptors(): array
    {
        return $this->decryptors;
    }

    /** Sets (or, with null, removes and destroys) the per-user {@see KeyRatchetHandle} for `$userId`. */
    public function setKeyRatchet(int|string $userId, ?KeyRatchetHandle $keyRatchet): void
    {
        $userId = (string) $userId;

        if (isset($this->keyRatchets[$userId]) && $this->keyRatchets[$userId] !== $keyRatchet) {
            $this->keyRatchets[$userId]->destroy();
        }

        if ($keyRatchet === null) {
            unset($this->keyRatchets[$userId]);

            return;
        }

        $this->keyRatchets[$userId] = $keyRatchet;
    }

    /** The {@see KeyRatchetHandle} for `$userId`, or null. */
    public function getKeyRatchet(int|string $userId): ?KeyRatchetHandle
    {
        return $this->keyRatchets[(string) $userId] ?? null;
    }

    /** Sets this client's own {@see KeyRatchetHandle}, destroying the previous one. */
    public function setSelfKeyRatchet(?KeyRatchetHandle $keyRatchet): void
    {
        if ($this->selfKeyRatchet !== null && $this->selfKeyRatchet !== $keyRatchet) {
            $this->selfKeyRatchet->destroy();
        }

        $this->selfKeyRatchet = $keyRatchet;
    }

    /** This client's own {@see KeyRatchetHandle}, or null. */
    public function getSelfKeyRatchet(): ?KeyRatchetHandle
    {
        return $this->selfKeyRatchet;
    }

    /** Destroys and drops every per-user decryptor. */
    public function clearDecryptors(): void
    {
        foreach ($this->decryptors as $decryptor) {
            $decryptor->destroy();
        }

        $this->decryptors = [];
    }

    /** Destroys and drops every key ratchet, including this client's own. */
    public function clearKeyRatchets(): void
    {
        foreach ($this->keyRatchets as $keyRatchet) {
            $keyRatchet->destroy();
        }

        $this->keyRatchets = [];
        $this->setSelfKeyRatchet(null);
    }

    /** Frees all handles and returns the state to a fresh passthrough baseline (version 0). */
    public function resetProtocolState(): void
    {
        $this->replaceEncryptor(null);
        $this->clearDecryptors();
        $this->clearKeyRatchets();
        $this->replaceSession(null);

        $this->protocolVersion = 0;
        $this->epoch = null;
        $this->pendingTransitionId = null;
        $this->pendingProtocolVersion = null;
        $this->latestPreparedTransitionVersion = null;
        $this->passthroughMode = true;
        $this->encryptFailureCount = 0;
        $this->decryptFailureCount = 0;
        $this->proposalFailureCount = 0;
        $this->keyPackageSent = false;
    }

    /** Destroys every native handle in dependency order: encryptor and decryptors, then key ratchets, then the session. */
    public function close(): void
    {
        $this->replaceEncryptor(null);
        $this->clearDecryptors();
        $this->clearKeyRatchets();
        $this->replaceSession(null);
    }

    /**
     * @param array<int|string> $userIds
     */
    public function addRecognizedUsers(array $userIds): void
    {
        foreach ($userIds as $userId) {
            $this->recognizedUserIds[(string) $userId] = true;
        }
    }

    /** Forgets `$userId` and destroys its decryptor and key ratchet. */
    public function removeRecognizedUser(int|string $userId): void
    {
        $userId = (string) $userId;

        unset($this->recognizedUserIds[$userId]);
        $this->setDecryptor($userId, null);
        $this->setKeyRatchet($userId, null);
    }

    /**
     * @return list<string>
     */
    public function recognizedUsers(): array
    {
        return array_map('strval', array_keys($this->recognizedUserIds));
    }

    /**
     * @return list<string>
     */
    public function recognizedUsersIncludingSelf(): array
    {
        $recognizedUsers = $this->recognizedUsers();

        if ($this->selfUserId !== null && ! isset($this->recognizedUserIds[$this->selfUserId])) {
            $recognizedUsers[] = $this->selfUserId;
        }

        return $recognizedUsers;
    }
}
