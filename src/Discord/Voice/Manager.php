<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP project.
 *
 * Copyright (c) 2015-present David Cole <david.cole1340@gmail.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Discord\Voice;

use Discord\Discord;
use Discord\Voice\Exceptions\Channels\CantJoinMoreThanOneChannelException;
use Discord\Voice\Exceptions\Channels\CantSpeakInChannelException;
use Discord\Voice\Exceptions\Channels\ChannelMustAllowVoiceException;
use Discord\Voice\Exceptions\Channels\EnterChannelDeniedException;
use Discord\Parts\Channel\Channel;
use Discord\Parts\WebSockets\VoiceServerUpdate;
use Discord\Parts\WebSockets\VoiceStateUpdate;
use Discord\WebSockets\Event;
use Discord\WebSockets\Op;
use Discord\WebSockets\VoicePayload;
use Evenement\EventEmitterTrait;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Manages many voice clients for the bot.
 *
 * @requires libopus - Linux | NOT TESTED - WINDOWS
 * @requires FFMPEG - Linux | NOT TESTED - WINDOWS
 *
 * @since 10.19.0
 */
final class Manager
{
    use EventEmitterTrait;

    /**
     * @param Discord               $discord
     * @param array<string, Client> $clients
     */
    public function __construct(
        protected Discord $discord,
        public array $clients = [],
    ) {
    }

    /**
     * Handles the creation of a new voice client and joins the specified channel.
     *
     * @param \Discord\Parts\Channel\Channel $channel
     * @param \Discord\Discord               $discord
     * @param bool                           $mute
     * @param bool                           $deaf
     *
     * @throws \Discord\Voice\Exceptions\Channels\ChannelMustAllowVoiceException
     * @throws \Discord\Voice\Exceptions\Channels\EnterChannelDeniedException
     * @throws \Discord\Voice\Exceptions\Channels\CantJoinMoreThanOneChannelException
     * @throws \Discord\Voice\Exceptions\Channels\CantSpeakInChannelException
     *
     * @return \React\Promise\PromiseInterface
     */
    public function joinChannel(Channel $channel, Discord $discord, bool $mute = false, bool $deaf = true): PromiseInterface
    {
        $deferred = new Deferred();

        try {
            if (! $channel->isVoiceBased()) {
                throw new ChannelMustAllowVoiceException();
            }

            $botperms = $channel->getBotPermissions();

            if (! $botperms->connect) {
                throw new EnterChannelDeniedException();
            }

            if (! $botperms->speak && ! $mute) {
                throw new CantSpeakInChannelException();
            }

            if (isset($this->clients[$channel->guild_id])) {
                throw new CantJoinMoreThanOneChannelException();
            }
        } catch (\Throwable $th) {
            $deferred->reject($th);

            return $deferred->promise();
        }

        // The same as new Client(...)
        $this->clients[$channel->guild_id] = Client::make(
            $this->discord,
            $channel,
            ['dnsConfig' => $discord->options['dnsConfig']],
            $deaf,
            $mute,
            $deferred,
            $this,
            false
        );

        $discord->on(Event::VOICE_STATE_UPDATE, fn ($state) => $this->stateUpdate($state, $channel));
        // Creates Voice Client and waits for the voice server update.
        $discord->on(Event::VOICE_SERVER_UPDATE, fn ($state, Discord $discord) => $this->serverUpdate($state, $channel, $discord, $deferred));

        $discord->send(VoicePayload::new(
            Op::OP_UPDATE_VOICE_STATE,
            [
                'guild_id' => $channel->guild_id,
                'channel_id' => $channel->id,
                'self_mute' => $mute,
                'self_deaf' => $deaf,
            ],
        ));

        return $deferred->promise();
    }

    /**
     * Retrieves the voice client for a given guild id.
     *
     * @param string|int $guildId
     *
     * @return \Discord\Voice\Client|null
     */
    public function getClient(string|int|Channel $guildChannelOrId): ?Client
    {
        if ($guildChannelOrId instanceof Channel) {
            $guildChannelOrId = $guildChannelOrId->guild_id;
        }

        if (! isset($this->clients[$guildChannelOrId])) {
            return null;
        }

        return $this->clients[$guildChannelOrId];
    }

    /**
     * Handles the voice state update event to update session information for the voice client.
     *
     * @param \Discord\Parts\WebSockets\VoiceStateUpdate $state
     * @param \Discord\Parts\Channel\Channel             $channel
     */
    protected function stateUpdate(VoiceStateUpdate $state, Channel $channel): void
    {
        if ($state->guild_id != $channel->guild_id) {
            return; // This voice state update isn't for our guild.
        }

        $this->getClient($channel)
            ->setData(['session' => $state->session_id, 'deaf' => $state->deaf, 'mute' => $state->mute]);

        $this->discord->getLogger()->info('received session id for voice session', ['guild' => $channel->guild_id, 'session_id' => $state->session_id]);
    }

    /**
     * Handles the voice server update event to create a new voice client with the provided state.
     *
     * @param \Discord\Parts\WebSockets\VoiceServerUpdate $state
     * @param \Discord\Parts\Channel\Channel              $channel
     * @param \Discord\Discord                            $discord
     * @param \React\Promise\Deferred                     $deferred
     */
    protected function serverUpdate(VoiceServerUpdate $state, Channel $channel, Discord $discord, Deferred $deferred): void
    {
        if ($state->guild_id !== $channel->guild_id) {
            return; // This voice server update isn't for our guild.
        }

        $this->discord->getLogger()->info('received token and endpoint for voice session', [
            'guild' => $channel->guild_id,
            'token' => '*****',
            'endpoint' => $state->endpoint,
        ]);

        $client = $this->getClient($channel);

        $client->once('ready', function () use (&$client, $deferred, $channel) {
            $this->discord->logger->info('voice manager is ready');
            $this->discord->voiceClients[$channel->guild_id] = $client;
            $deferred->resolve($client);
        });
        $client->once('error', function ($e) use ($deferred) {
            $this->discord->logger->error('error initializing voice manager', ['e' => $e->getMessage()]);
            $deferred->reject($e);
        });
        $client->once('close', function () use ($channel) {
            $this->discord->logger->warning('voice manager closed');
            unset($this->discord->voiceClients[$channel->guild_id]);
        });

        $client->setData(
            array_merge(
                $client->data,
                [
                'token' => $state->token,
                'endpoint' => $state->endpoint,
                'session' => $client->data['session'] ?? null,
            ],
                ['dnsConfig' => $discord->options['dnsConfig']]
            )
        );
    }
}
