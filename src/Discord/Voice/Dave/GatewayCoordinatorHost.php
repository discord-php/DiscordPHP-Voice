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

use Discord\Voice\VoiceClient;
use Discord\WebSockets\VoicePayload;
use Psr\Log\LoggerInterface;

/**
 * Narrow interface exposing WS capabilities required by GatewayCoordinator.
 *
 * Implemented by {@see \Discord\Voice\Client\WS}.
 */
interface GatewayCoordinatorHost
{
    /**
     * Sends a DAVE binary frame over the voice WebSocket.
     */
    public function sendDaveBinary(int $opcode, string $payload = ''): void;

    /**
     * Sends a JSON voice payload over the voice WebSocket.
     */
    public function send(VoicePayload|array $data): void;

    /**
     * Closes the underlying WebSocket connection.
     */
    public function closeConnection(): void;

    /**
     * Returns the PSR-3 logger for this voice connection.
     */
    public function getLogger(): LoggerInterface;

    /**
     * Returns the DAVE protocol state for this connection.
     */
    public function getDaveState(): State;

    /**
     * Returns the VoiceClient associated with this connection.
     */
    public function getVoiceClient(): VoiceClient;

    /**
     * Returns the maximum DAVE protocol version supported at runtime.
     */
    public function getMaxDaveProtocolVersion(): int;
}
