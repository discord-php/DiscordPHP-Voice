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

    public int $protocolVersion = 0;

    public ?int $epoch = null;

    public ?int $pendingTransitionId = null;

    public ?int $pendingProtocolVersion = null;

    public bool $passthroughMode = true;

    public ?string $externalSenderPackage = null;

    public function setProtocolVersion(int $version): void
    {
        $this->protocolVersion = $version;
        $this->passthroughMode = $version <= 0;
    }

    public function prepareTransition(int $transitionId, ?int $protocolVersion = null): void
    {
        $this->pendingTransitionId = $transitionId;
        $this->pendingProtocolVersion = $protocolVersion;
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
        unset($this->recognizedUserIds[(string) $userId]);
    }

    /**
     * @return array<int, string>
     */
    public function recognizedUsers(): array
    {
        return array_keys($this->recognizedUserIds);
    }
}
