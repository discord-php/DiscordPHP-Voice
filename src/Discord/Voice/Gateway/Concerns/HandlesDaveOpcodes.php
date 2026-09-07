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

namespace Discord\Voice\Gateway\Concerns;

use Discord\WebSockets\Payload;

/**
 * The thin VOICE_DAVE_* opcode proxies of {@see \Discord\Voice\Gateway\WS}.
 *
 * Every method here just forwards to {@see \Discord\Voice\Dave\GatewayCoordinator},
 * where the real MLS/transition logic lives. They exist so the WS
 * {@see \Discord\Voice\Gateway\WS::VOICE_OP_HANDLERS} dispatch table and the
 * reflection-based DAVE test surface stay stable.
 *
 * @since 10.20.0
 */
trait HandlesDaveOpcodes
{
    /**
     * Routes the VOICE_DAVE_PREPARE_TRANSITION opcode to the {@see \Discord\Voice\Dave\GatewayCoordinator::handleDavePrepareTransition()}.
     *
     * @param Payload $data
     */
    protected function handleDavePrepareTransition($data): void
    {
        $this->getCoordinator()->handleDavePrepareTransition($data);
    }

    /**
     * Routes the VOICE_DAVE_EXECUTE_TRANSITION opcode to the {@see \Discord\Voice\Dave\GatewayCoordinator::handleDaveExecuteTransition()}.
     *
     * @param Payload $data
     */
    protected function handleDaveExecuteTransition($data): void
    {
        $this->getCoordinator()->handleDaveExecuteTransition($data);
    }

    /**
     * Routes the VOICE_DAVE_TRANSITION_READY opcode to the {@see \Discord\Voice\Dave\GatewayCoordinator::handleDaveTransitionReady()}.
     *
     * @param Payload $data
     */
    protected function handleDaveTransitionReady($data): void
    {
        $this->getCoordinator()->handleDaveTransitionReady($data);
    }

    /**
     * Routes the VOICE_DAVE_PREPARE_EPOCH opcode to the {@see \Discord\Voice\Dave\GatewayCoordinator::handleDavePrepareEpoch()}.
     *
     * @param Payload $data
     */
    protected function handleDavePrepareEpoch($data): void
    {
        $this->getCoordinator()->handleDavePrepareEpoch($data);
    }

    /**
     * Routes the VOICE_DAVE_MLS_EXTERNAL_SENDER_PACKAGE opcode to the {@see \Discord\Voice\Dave\GatewayCoordinator::handleDaveMlsExternalSender()}.
     *
     * @param Payload $data
     */
    protected function handleDaveMlsExternalSender($data): void
    {
        $this->getCoordinator()->handleDaveMlsExternalSender($data);
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
    protected function handleDaveMlsKeyPackage($data): void
    {
        $this->getCoordinator()->handleDaveMlsKeyPackage($data);
    }

    /**
     * Routes the VOICE_DAVE_MLS_PROPOSALS opcode to the {@see \Discord\Voice\Dave\GatewayCoordinator::handleDaveMlsProposals()}.
     *
     * @param Payload $data
     */
    protected function handleDaveMlsProposals($data): void
    {
        $this->getCoordinator()->handleDaveMlsProposals($data);
    }

    /**
     * Routes the VOICE_DAVE_MLS_COMMIT_WELCOME opcode to the {@see \Discord\Voice\Dave\GatewayCoordinator::handleDaveMlsCommitWelcome()}.
     *
     * @param Payload $data
     */
    protected function handleDaveMlsCommitWelcome($data): void
    {
        $this->getCoordinator()->handleDaveMlsCommitWelcome($data);
    }

    /**
     * Routes the VOICE_DAVE_MLS_ANNOUNCE_COMMIT_TRANSITION opcode to the {@see \Discord\Voice\Dave\GatewayCoordinator::handleDaveMlsAnnounceCommitTransition()}.
     *
     * @param Payload $data
     */
    protected function handleDaveMlsAnnounceCommitTransition($data): void
    {
        $this->getCoordinator()->handleDaveMlsAnnounceCommitTransition($data);
    }

    /**
     * Routes the VOICE_DAVE_MLS_WELCOME opcode to the {@see \Discord\Voice\Dave\GatewayCoordinator::handleDaveMlsWelcome()}.
     *
     * @param Payload $data
     */
    protected function handleDaveMlsWelcome($data): void
    {
        $this->getCoordinator()->handleDaveMlsWelcome($data);
    }

    /**
     * Routes the VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME opcode to the {@see \Discord\Voice\Dave\GatewayCoordinator::handleDaveMlsInvalidCommitWelcome()}.
     *
     * @param Payload $data
     */
    protected function handleDaveMlsInvalidCommitWelcome($data): void
    {
        $this->getCoordinator()->handleDaveMlsInvalidCommitWelcome($data);
    }
}
