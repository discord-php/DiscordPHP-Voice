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

namespace Discord\Voice\Client;

use Discord\Discord;
use Discord\Factory\SocketFactory;
use Discord\Parts\Voice\UserConnected;
use Discord\Voice\Any;
use Discord\Voice\Client;
use Discord\Voice\Flags;
use Discord\Voice\Hello;
use Discord\Voice\Platform;
use Discord\Voice\Ready;
use Discord\Voice\Resumed;
use Discord\Voice\SessionDescription;
use Discord\Voice\Speaking;
use Discord\WebSockets\Op;
use Discord\WebSockets\Payload;
use Discord\WebSockets\VoicePayload;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\Message;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;

/**
 * Handles the Discord voice WebSocket connection.
 *
 * This class manages the WebSocket connection to the Discord voice gateway,
 * handling events, sending messages, and managing the voice connection state.
 *
 * @since 10.19.0
 */
final class WS
{
    /**
     * The maximum DAVE protocol version supported.
     */
    public const MAX_DAVE_PROTOCOL_VERSION = 0;

    /**
     * Dispatch table mapping Discord Voice Gateway opcodes to handler methods.
     *
     * @var array<int,string> Method name indexed by opcode constant.
     */
    public const VOICE_OP_HANDLERS = [
        Op::VOICE_READY => 'handleReady',
        Op::VOICE_SESSION_DESCRIPTION => 'handleSessionDescription',
        Op::VOICE_SPEAKING => 'handleSpeaking',
        Op::VOICE_HEARTBEAT_ACK => 'handleHeartbeatAck',
        Op::VOICE_HELLO => 'handleHello',
        Op::VOICE_RESUMED => 'handleResumed',
        Op::VOICE_CLIENT_CONNECT => 'handleClientConnect',
        Op::VOICE_CLIENT_DISCONNECT => 'handleClientDisconnect',
        Op::VOICE_CLIENT_UNKNOWN_15 => 'handleAny',
        Op::VOICE_CLIENT_UNKNOWN_18 => 'handleFlags',
        Op::VOICE_CLIENT_PLATFORM => 'handlePlatform',
        Op::VOICE_DAVE_PREPARE_TRANSITION => 'handleDavePrepareTransition',
        Op::VOICE_DAVE_EXECUTE_TRANSITION => 'handleDaveExecuteTransition',
        Op::VOICE_DAVE_TRANSITION_READY => 'handleDaveTransitionReady',
        Op::VOICE_DAVE_PREPARE_EPOCH => 'handleDavePrepareEpoch',
        Op::VOICE_DAVE_MLS_EXTERNAL_SENDER => 'handleDaveMlsExternalSender',
        Op::VOICE_DAVE_MLS_KEY_PACKAGE => 'handleDaveMlsKeyPackage',
        Op::VOICE_DAVE_MLS_PROPOSALS => 'handleDaveMlsProposals',
        Op::VOICE_DAVE_MLS_COMMIT_WELCOME => 'handleDaveMlsCommitWelcome',
        Op::VOICE_DAVE_MLS_ANNOUNCE_COMMIT_TRANSITION => 'handleDaveMlsAnnounceCommitTransition',
        Op::VOICE_DAVE_MLS_WELCOME => 'handleDaveMlsWelcome',
        Op::VOICE_DAVE_MLS_INVALID_COMMIT_WELCOME => 'handleDaveMlsInvalidCommitWelcome',

        Op::CLOSE_VOICE_DISCONNECTED => 'handleCloseVoiceDisconnected',
    ];

    /**
     * The SocketFactory instance for creating UDP sockets.
     */
    protected SocketFactory $udpfac;

    /**
     * The WebSocket instance for the voice connection.
     */
    protected WebSocket $socket;

    /**
     * The Discord voice gateway version.
     *
     * @see https://discord.com/developers/docs/topics/voice-connections#voice-gateway-versioning-gateway-versions
     */
    protected static $version = 8;

    /**
     * The Voice WebSocket mode.
     *
     * @link https://discord.com/developers/docs/topics/voice-connections#transport-encryption-modes
     */
    public string $mode = 'aead_aes256_gcm_rtpsize';

    /**
     * The secret key used for encrypting voice.
     */
    public ?string $secretKey;

    /**
     * The raw secret key.
     */
    public ?array $rawKey;

    /**
     * The SSRC identifier for the voice connection client.
     */
    public null|string|int $ssrc;

    /**
     * Indicates whether the login frame has been sent.
     */
    private bool $sentLoginFrame = false;

    /**
     * The heartbeat timer for the voice connection.
     */
    protected TimerInterface $heartbeat;

    /**
     * The heartbeat interval for the voice connection.
     */
    protected $hbInterval;

    /**
     * The heartbeat sequence number.
     *
     * This is used to track the sequence of heartbeat messages sent to the voice gateway.
     */
    protected int $hbSequence = 0;

    /**
     * The WebSocket connection for the voice client.
     *
     * This is used to send and receive messages over the WebSocket connection.
     */
    public function __construct(
        public Client $vc,
        protected ?Discord $discord = null,
        public ?array $data = [],
    ) {
        $this->data ??= $this->vc->data;
        $this->discord ??= $this->vc->discord;

        if (! isset($this->data['endpoint'])) {
            throw new \InvalidArgumentException('Endpoint is required for the voice WebSocket connection.');
        }

        $f = new Connector();

        /** @var PromiseInterface<WebSocket> */
        $f('wss://'.$this->data['endpoint'].'?v='.self::$version)->then(
            fn (WebSocket $ws) => $this->handleConnection($ws),
            fn (\Throwable $e) => $this->discord->logger->error(
                'Failed to connect to voice gateway: {error}',
                ['error' => $e->getMessage()]
            ) && $this->vc->emit('error', arguments: [$e])
        );
    }

    /**
     * Creates a new instance of the WS class.
     *
     * @param Client       $vc
     * @param null|Discord $discord
     * @param null|array   $data
     *
     * @return WS
     */
    public static function make(Client $vc, ?Discord $discord = null, ?array $data = null): self
    {
        return new self($vc, $discord, $data);
    }

    /**
     * Handles a WebSocket connection.
     */
    public function handleConnection(WebSocket $ws): void
    {
        $this->discord->logger->debug('connected to voice websocket');

        $this->udpfac = new SocketFactory($this->discord->loop, ws: $this);

        $this->socket = $this->vc->ws = $ws;

        $ws->on('message', function (Message $message): void {
            if (($data = json_decode($message->getPayload(), true)) === false) {
                return;
            }
            $data = Payload::fromArray($data);

            $this->vc->emit('ws-message', [$message, $this->vc]);

            if (isset(self::VOICE_OP_HANDLERS[$data->op])) {
                $handler = self::VOICE_OP_HANDLERS[$data->op];
                $this->$handler($data);
            } else {
                $this->discord->getLogger()->debug('unknown voice op', ['op' => $data->op]);
                $this->handleUndocumented($data);
            }
        });

        $ws->on('error', function ($e): void {
            $this->discord->logger->error('error with voice websocket', ['e' => $e->getMessage()]);
            $this->vc->emit('ws-error', [$e]);
        });

        $ws->on('close', [$this, 'handleClose']);

        if (! $this->sentLoginFrame) {
            $this->handleSendingOfLoginFrame();
            $this->sentLoginFrame = true;
        } elseif (isset(
            $this->data['token'],
            $this->data['seq'],
            $this->discord->voice_sessions[$this->vc->channel->guild_id]
        )) {
            $this->handleResume();
        } else {
            $this->discord->getLogger()->debug('existing voice session or data not found, re-sending identify', ['guild_id' => $this->vc->channel->guild_id]);
            $this->handleSendingOfLoginFrame();
        }
    }

    /**
     * Sends a message to the voice websocket.
     */
    public function send(VoicePayload|array $data): void
    {
        $this->socket->send(json_encode($data));
    }

    /**
     * Handles the "ready" event for the voice client, initializing UDP connection and heartbeat.
     *
     * @param Payload $data The data object containing voice server connection details:
     *                      - $data->d['ssrc']:  The synchronization source identifier.
     *                      - $data->d['ip']:    The IP address for the UDP connection.
     *                      - $data->d['port']:  The port for the UDP connection.
     *                      - $data->d['modes']: Supported encryption modes.
     */
    protected function handleReady(Payload $data): void
    {
        /** @var Ready */
        $ready = $this->discord->factory(Ready::class, (array) $data->d, true);

        $this->vc->ssrc = $ready->ssrc;
        $this->discord->logger->debug('received voice ready packet', ['data' => json_decode(json_encode($data->d), true)]);

        /** @var PromiseInterface */
        $this->udpfac->createClient("{$ready->ip}:".$ready->port)->then(function (UDP $client) use ($ready): void {
            $this->vc->udp = $client;
            $client->handleSsrcSending()
                ->handleHeartbeat()
                ->handleErrors()
                ->decodeOnce();

            $client->ip = $ready->ip;
            $client->port = $ready->port;
            $client->ssrc = $ready->ssrc;
        }, function (\Throwable $e): void {
            $this->discord->logger->error('error while connecting to udp', ['e' => $e->getMessage()]);
            $this->vc->emit('error', [$e]);
        });
    }

    /**
     * Handles the session description packet received from the Discord voice server.
     *
     * @param Payload $data
     */
    protected function handleSessionDescription(Payload $data): void
    {
        /** @var SessionDescription */
        $sd = $this->discord->factory(SessionDescription::class, (array) $data->d, true);
        
        $this->vc->ready = true;
        $this->mode = $sd->mode === $this->mode ? $this->mode : 'aead_aes256_gcm_rtpsize';
        $this->rawKey = $data->d['secret_key'];
        $this->secretKey = $sd->secret_key;

        $this->discord->logger->debug('received description packet, vc ready', ['data' => $sd->__debugInfo()]);

        if (! $this->vc->reconnecting) {
            $this->vc->emit('ready', [$this->vc]);
        } else {
            $this->vc->reconnecting = false;
            $this->vc->emit('resumed', [$this->vc]);
            # TODO: check if this can fix the reconnect issue
            $this->vc->emit('ready', [$this->vc]);
        }

        if (! $this->vc->deaf && $this->secretKey) {
            $this->vc->udp->handleMessages($this->secretKey);
        }
    }

    /**
     * Handles the speaking state of a user.
     *
     * @param Payload $data The data object received from the WebSocket.
     */
    protected function handleSpeaking(Payload $data): void
    {
        /** @var Speaking */
        $speaking = $this->discord->factory(Speaking::class, (array) $data->d, true);

        $this->discord->logger->debug('received speaking packet', ['data' => json_decode(json_encode($data->d), true)]);
        $this->vc->speakingStatus[$speaking->user_id] = $speaking;
        $this->vc->emit('speaking', [$speaking->speaking, $speaking->user_id, $this->vc]);
        $this->vc->emit("speaking.{$speaking->user_id}", [$speaking->speaking, $this->vc]);
    }

    /**
     * Handles the heartbeat acknowledgement from the voice WebSocket connection.
     *
     * @param Payload $data
     */
    public function handleHeartbeatAck(Payload $data): void
    {
        $diff = (microtime(true) - $data->d['t']) * 1000;

        $this->discord->logger->debug('received heartbeat ack', ['response_time' => $diff]);
        $this->vc->emit('ws-ping', [$diff]);
        $this->vc->emit('ws-heartbeat-ack', [$data->d['t']]);
    }

    /**
     * Handles the "Hello" event from the Discord voice server.
     *
     * @param Payload $data
     */
    protected function handleHello(Payload $data): void
    {
        /** @var Hello */
        $hello = $this->discord->factory(Hello::class, (array) $data->d, true);
        
        $this->hbInterval = $this->vc->heartbeatInterval = $hello->heartbeat_interval;
        $this->sendHeartbeat();
        $this->heartbeat = $this->discord->loop->addPeriodicTimer(
            $this->hbInterval / 1000,
            fn () => $this->sendHeartbeat()
        );
    }

    /**
     * Handles the 'resumed' event for the voice client.
     *
     * @param Payload $data
     */
    protected function handleResumed(Payload $data): void
    {
        /** @var Resumed */
        $resumed = $this->discord->factory(Resumed::class, (array) $data->d, true);
        $this->discord->getLogger()->debug('received resumed packet', ['data' => $resumed]);
    }

    /**
     * Handles the event when a client connects to the voice server.
     *
     * @param Payload $data
     */
    protected function handleClientConnect(Payload $data): void
    {
        $this->discord->getLogger()->debug('received client connect packet', ['data' => $data]);
        // "d" contains an array with ['user_ids' => array<string>]
        $this->vc->users = array_map(fn (int $userId) => $this->discord->getFactory()->part(UserConnected::class, ['user_id' => $userId]), $data->d['user_ids']);
    }

    /**
     * Handles the event when a client disconnects from the voice server.
     *
     * @param Payload $data
     */
    protected function handleClientDisconnect(Payload $data): void
    {
        $this->discord->logger->debug('received client disconnected packet', ['data' => $data]);
        unset($this->vc->clientsConnected[$data->d['user_id']]);
    }

    /**
     * Handles the any event from the voice server.
     *
     * @param Payload $data
     */
    public function handleAny(Payload $data): void
    {
        $any = $this->discord->factory(Any::class, (array) $data->d, true);

        $this->discord->logger->debug('received any packet', ['data' => $any->__debugInfo()]);
    }

    /**
     * Handles the flags event from the voice server.
     *
     * @param Payload $data
     */
    protected function handleFlags(Payload $data): void
    {
        $flags = $this->discord->factory(Flags::class, (array) $data->d, true);

        $this->discord->logger->debug('received flags packet', ['data' => $flags->__debugInfo()]);
    }

    /**
     * Handles the platform event from the voice server.
     *
     * @param Payload $data
     */
    protected function handlePlatform(Payload $data): void
    {
        $platform = $this->discord->factory(Platform::class, (array) $data->d, true);

        $this->discord->logger->debug('received platform packet', ['data' => $platform->__debugInfo()]);
    }

    /**
     * Handles undocumented voice opcodes not intended for use by bots.
     *
     * @param Payload $data
     */
    protected function handleUndocumented(Payload $data): void
    {
    }

    protected function handleDavePrepareTransition($data): void
    {
        $this->discord->logger->debug('DAVE Prepare Transition', ['data' => $data]);
        // Prepare local state necessary to perform the transition
        $this->send(VoicePayload::new(
            Op::VOICE_DAVE_TRANSITION_READY,
            ['transition_id' => $data->d['transition_id']],
        ));
    }

    protected function handleDaveExecuteTransition($data): void
    {
        $this->discord->logger->debug('DAVE Execute Transition', ['data' => $data]);
        // Execute the transition
        // Update local state to reflect the new protocol context
    }

    protected function handleDaveTransitionReady($data): void
    {
        $this->discord->logger->debug('DAVE Transition Ready', ['data' => $data]);
        // Handle transition ready state
    }

    protected function handleDavePrepareEpoch($data): void
    {
        $this->discord->logger->debug('DAVE Prepare Epoch', ['data' => $data]);
        // Prepare local MLS group with parameters appropriate for the DAVE protocol version
        $this->send(VoicePayload::new(
            Op::VOICE_DAVE_MLS_KEY_PACKAGE,
            [
                'epoch_id' => $data->d['epoch_id'],
                //'key_package' => $this->generateKeyPackage(),
            ],
        ));
    }

    protected function handleDaveMlsExternalSender($data): void
    {
        $this->discord->logger->debug('DAVE MLS External Sender', ['data' => $data]);
        // Handle external sender public key and credential
    }

    protected function handleDaveMlsKeyPackage($data): void
    {
        $this->discord->logger->debug('DAVE MLS Key Package', ['data' => $data]);
        // Handle MLS key package
    }

    protected function handleDaveMlsProposals($data): void
    {
        $this->discord->logger->debug('DAVE MLS Proposals', ['data' => $data]);
        // Handle MLS proposals
        $this->send(VoicePayload::new(
            Op::VOICE_DAVE_MLS_COMMIT_WELCOME,
            [
                //'commit' => $this->generateCommit(),
                //'welcome' => $this->generateWelcome(),
            ],
        ));
    }

    protected function handleDaveMlsCommitWelcome($data): void
    {
        $this->discord->logger->debug('DAVE MLS Commit Welcome', ['data' => $data]);
        // Handle MLS commit and welcome messages
    }

    protected function handleDaveMlsAnnounceCommitTransition($data)
    {
        // Handle MLS announce commit transition
        $this->discord->logger->debug('DAVE MLS Announce Commit Transition', ['data' => $data]);
    }

    protected function handleDaveMlsWelcome($data)
    {
        // Handle MLS welcome message
        $this->discord->logger->debug('DAVE MLS Welcome', ['data' => $data]);
    }

    protected function handleDaveMlsInvalidCommitWelcome($data)
    {
        $this->discord->logger->debug('DAVE MLS Invalid Commit Welcome', ['data' => $data]);
        // Handle invalid commit or welcome message
        // Reset local group state and generate a new key package
        $this->send(VoicePayload::new(
            Op::VOICE_DAVE_MLS_KEY_PACKAGE,
            [
                //'key_package' => $this->generateKeyPackage(),
            ],
        ));
    }

    /**
     * Sends a heartbeat to the voice WebSocket.
     */
    public function sendHeartbeat(): void
    {
        $this->send(VoicePayload::new(
            Op::VOICE_HEARTBEAT,
            [
                't' => (int) microtime(true),
                'seq_ack' => ++$this->hbSequence,
            ]
        ));
        $this->discord->logger->debug('sending heartbeat');
        $this->vc->emit('ws-heartbeat', []);
    }

    /**
     * Handles the close event of the WebSocket connection.
     *
     * @param int    $op     The opcode of the close event.
     * @param string $reason The reason for closing the connection.
     */
    public function handleClose(int $op, string $reason): void
    {
        $this->discord->logger->warning('voice websocket closed', ['op' => $op, 'reason' => $reason]);
        $this->vc->emit('ws-close', [$op, $reason, $this]);

        $this->vc->clientsConnected = [];

        // Cancel heartbeat timers
        if (null !== $this->vc->heartbeat) {
            $this->discord->loop->cancelTimer($this->vc->heartbeat);
            $this->vc->heartbeat = null;
        }

        // Close UDP socket.
        if (isset($this->vc->udp)) {
            $this->discord->logger->warning('closing UDP client');
            $this->vc->udp->close();
        }

        $this->socket->close();
        $this->discord->voice_sessions[$this->vc->channel->guild_id] = null;

        // Don't reconnect on a critical opcode or if closed by user.
        if (in_array($op, Op::getCriticalVoiceCloseCodes()) || $this?->vc->userClose) {
            $this->discord->logger->warning('received critical opcode - not reconnecting', ['op' => $op, 'reason' => $reason]);
            $this->vc->voice_sessions[$this->vc->channel->guild_id] = null;
            $this->vc->close();
            $this->vc->emit('close');

            return;
        }

        $this->discord->logger->warning('reconnecting in 2 seconds');

        // Retry connect after 2 seconds
        $this->discord->loop->addTimer(2, function (): void {
            $this->vc->reconnecting = true;
            $this->vc->sentLoginFrame = false;
            $this->sentLoginFrame = false;

            $this->vc->boot();
        });
    }

    /**
     * Handles sending the login frame to the voice WebSocket.
     *
     * This method sends the initial identification payload to the voice gateway
     * to establish the voice connection.
     *
     * @link https://discord.com/developers/docs/topics/voice-connections#establishing-a-voice-websocket-connection-example-voice-identify-payload
     */
    public function handleSendingOfLoginFrame(): void
    {
        if ($this->sentLoginFrame) {
            return;
        }

        $data = [
            'server_id' => $this->vc->channel->guild_id,
            'user_id' => $this->data['user_id'],
            'token' => $this->data['token'],
            'max_dave_protocol_version' => self::MAX_DAVE_PROTOCOL_VERSION,
        ];
        if (isset($this->discord->voice_sessions[$this->vc->channel->guild_id])) {
            $this->data['session'] = $this->discord->voice_sessions[$this->vc->channel->guild_id];
            $data['session_id'] = $this->data['session'];
        }

        $payload = VoicePayload::new(Op::VOICE_IDENTIFY, $data);

        $this->discord->logger->debug('sending identify', ['packet' => $payload->__debugInfo()]);

        $this->send($payload);
        $this->sentLoginFrame = true;
        $this->vc->sentLoginFrame = true;
    }

    /**
     * Resumes a previously established voice connection.
     */
    protected function handleResume(): void
    {
        $payload = Payload::new(
            Op::VOICE_RESUME,
            [
                'server_id' => $this->vc->channel->guild_id,
                'session_id' => $this->discord->voice_sessions[$this->vc->channel->guild_id],
                'token' => $this->data['token'],
                'seq_ack' => $this->data['seq'],
            ]
        );

        $this->discord->logger->debug('sending identify (resume)', ['packet' => $payload->__debugInfo()]);

        $this->send($payload);
    }
}
