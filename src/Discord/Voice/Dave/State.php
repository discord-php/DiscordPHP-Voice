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

final class State
{
    /** @var array<string, bool> */
    private array $recognizedUserIds = [];

    /** @var array<string, DecryptorHandle> */
    private array $decryptors = [];

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

    public function __destruct()
    {
        $this->close();
    }

    public function setIdentity(int|string $selfUserId, int|string|null $groupId): void
    {
        $this->selfUserId = (string) $selfUserId;
        $this->groupId = $groupId === null ? null : (int) $groupId;
    }

    public function setProtocolVersion(int $version): void
    {
        $this->protocolVersion = $version;
        $this->passthroughMode = $version <= 0;
    }

    public function prepareTransition(int $transitionId, ?int $protocolVersion = null): void
    {
        $this->pendingTransitionId = $transitionId;
        $this->pendingProtocolVersion = $protocolVersion;

        if ($protocolVersion !== null) {
            $this->latestPreparedTransitionVersion = $protocolVersion;
        }
    }

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

    public function prepareEpoch(int $epoch): void
    {
        $this->epoch = $epoch;
    }

    public function recordGatewaySequence(?int $sequence): void
    {
        if ($sequence === null) {
            return;
        }

        $this->lastReceivedSequence = $sequence;
    }

    public function replaceSession(?SessionHandle $session): void
    {
        if ($this->session !== null && $this->session !== $session) {
            $this->session->destroy();
        }

        $this->session = $session;
    }

    public function replaceEncryptor(?EncryptorHandle $encryptor): void
    {
        if ($this->encryptor !== null && $this->encryptor !== $encryptor) {
            $this->encryptor->destroy();
        }

        $this->encryptor = $encryptor;
    }

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

    public function getDecryptor(int|string $userId): ?DecryptorHandle
    {
        return $this->decryptors[(string) $userId] ?? null;
    }

    public function clearDecryptors(): void
    {
        foreach ($this->decryptors as $decryptor) {
            $decryptor->destroy();
        }

        $this->decryptors = [];
    }

    public function resetProtocolState(): void
    {
        $this->replaceSession(null);
        $this->replaceEncryptor(null);
        $this->clearDecryptors();

        $this->protocolVersion = 0;
        $this->epoch = null;
        $this->pendingTransitionId = null;
        $this->pendingProtocolVersion = null;
        $this->latestPreparedTransitionVersion = null;
        $this->passthroughMode = true;
        $this->encryptFailureCount = 0;
        $this->decryptFailureCount = 0;
        $this->proposalFailureCount = 0;
    }

    public function close(): void
    {
        $this->replaceSession(null);
        $this->replaceEncryptor(null);
        $this->clearDecryptors();
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

    public function removeRecognizedUser(int|string $userId): void
    {
        $userId = (string) $userId;

        unset($this->recognizedUserIds[$userId]);
        $this->setDecryptor($userId, null);
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
