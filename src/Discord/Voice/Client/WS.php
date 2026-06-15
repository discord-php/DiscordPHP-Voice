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

namespace Discord\Voice\Client;

use Discord\Discord;
use Discord\Factory\SocketFactory;
use Discord\Parts\Voice\UserConnected;
use Discord\Voice\Any;
use Discord\Voice\Client;
use Discord\Voice\Dave\BinaryFrame;
use Discord\Voice\Dave\GatewayCoordinator;
use Discord\Voice\Dave\GatewayCoordinatorHost;
use Discord\Voice\Dave\Runtime as DaveRuntime;
use Discord\Voice\Dave\State as DaveState;
use Discord\Voice\Exceptions\Libraries\LibDaveNotFoundException;
use Discord\Voice\Flags;
use Discord\Voice\Hello;
use Discord\Voice\Platform;
use Discord\Voice\Ready;
use Discord\Voice\Resumed;
use Discord\Voice\SessionDescription;
use Discord\Voice\Speaking;
use Discord\Voice\VoiceClient;
use Discord\WebSockets\Op;
use Discord\WebSockets\Payload;
use Discord\WebSockets\VoicePayload;
use Psr\Log\LoggerInterface;
use Ratchet\Client\Connector;
use Ratchet\Client\WebSocket;
use Ratchet\RFC6455\Messaging\Frame;
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
final class WS implements GatewayCoordinatorHost
{
    /**
     * The maximum DAVE protocol version supported.
     */
    public const MAX_DAVE_PROTOCOL_VERSION = 1;

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
        Op::VOICE_DAVE_PREPARE_EPOCH => 'handleDavePrepareEpoch',
        Op::VOICE_DAVE_MLS_EXTERNAL_SENDER => 'handleDaveMlsExternalSender',
        Op::VOICE_DAVE_MLS_PROPOSALS => 'handleDaveMlsProposals',
        Op::VOICE_DAVE_MLS_ANNOUNCE_COMMIT_TRANSITION => 'handleDaveMlsAnnounceCommitTransition',
        Op::VOICE_DAVE_MLS_WELCOME => 'handleDaveMlsWelcome',
        Op::VOICE_DAVE_TRANSITION_READY => 'handleDaveTransitionReady',
        Op::VOICE_DAVE_MLS_KEY_PACKAGE => 'handleDaveMlsKeyPackage',
        Op::VOICE_DAVE_MLS_COMMIT_WELCOME => 'handleDaveMlsCommitWelcome',
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
    protected ?string $secretKey;

    /**
     * The raw secret key.
     */
    protected ?array $rawKey;

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
     * DAVE protocol state for this voice connection.
     */
    protected DaveState $daveState;

    /**
     * Maximum supported DAVE protocol version for this runtime.
     */
    protected int $maxDaveProtocolVersion = self::MAX_DAVE_PROTOCOL_VERSION;

    /**
     * The DAVE gateway coordinator for this voice connection.
     */
    private ?GatewayCoordinator $coordinator = null;

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
        $this->daveState = new DaveState();
        $this->daveState->setIdentity(
            (string) ($this->data['user_id'] ?? $this->discord->id ?? ''),
            $this->resolveDaveGroupId()
        );
        // Never advertise a protocol version beyond what both this library and the runtime can support.
        if (! DaveRuntime::isAvailable()) {
            throw LibDaveNotFoundException::fromRuntimeError();
        }
        $this->maxDaveProtocolVersion = min(self::MAX_DAVE_PROTOCOL_VERSION, DaveRuntime::maxProtocolVersion());
        $this->coordinator = new GatewayCoordinator($this);

        if (! isset($this->data['endpoint'])) {
            throw new \InvalidArgumentException('Endpoint is required for the voice WebSocket connection.');
        }

        $this->discord->logger->debug('Creating new voice websocket', ['endpoint' => $this->data['endpoint']]);

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

        $this->udpfac = new SocketFactory(ws: $this);

        $this->socket = $this->vc->ws = $ws;

        $ws->on('message', function (Message $message): void {
            $payload = $message->getPayload();

            $data = json_decode($payload, true);
            if (! is_array($data)) {
                $this->handleBinaryVoiceMessage($payload);

                return;
            }

            $this->recordGatewaySequence(isset($data['seq']) ? (int) $data['seq'] : null);
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
        } elseif ($this->vc->reconnecting && isset($this->data['token'], $this->discord->voice_sessions[$this->vc->channel->guild_id])) {
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
     * Sends a DAVE binary payload over the voice websocket.
     */
    public function sendDaveBinary(int $opcode, string $payload = ''): void
    {
        $this->discord->logger->debug('sending DAVE binary packet', [
            'opcode' => $opcode,
            'payload_length' => strlen($payload),
        ]);

        $this->socket->send(new Frame(
            (new BinaryFrame(null, $opcode, $payload))->toClientPayload(),
            true,
            Frame::OP_BINARY
        ));
    }

    /**
     * Closes the underlying WebSocket connection.
     */
    public function closeConnection(): void
    {
        $this->socket->close();
    }

    /**
     * Returns the PSR-3 logger for this voice connection.
     */
    public function getLogger(): LoggerInterface
    {
        return $this->discord->logger;
    }

    /**
     * Returns the VoiceClient associated with this connection.
     */
    public function getVoiceClient(): VoiceClient
    {
        return $this->vc;
    }

    /**
     * Returns the maximum DAVE protocol version supported at runtime.
     */
    public function getMaxDaveProtocolVersion(): int
    {
        return $this->maxDaveProtocolVersion;
    }

    /**
     * Returns the GatewayCoordinator, creating it lazily if needed.
     *
     * Lazy initialisation allows tests that bypass the constructor to still
     * exercise DAVE methods without explicitly injecting the coordinator.
     */
    private function getCoordinator(): GatewayCoordinator
    {
        if ($this->coordinator === null) {
            $this->coordinator = new GatewayCoordinator($this);
        }

        return $this->coordinator;
    }

    /**
     * Handles binary voice websocket frames.
     */
    protected function handleBinaryVoiceMessage(string $payload): void
    {
        $frame = BinaryFrame::fromServerPayload($payload);
        if ($frame === null) {
            return;
        }

        $this->recordGatewaySequence($frame->sequence);
        $this->vc->emit('ws-binary-message', [$frame, $this->vc]);

        if (! isset(self::VOICE_OP_HANDLERS[$frame->opcode])) {
            $this->discord->logger->debug('unknown voice binary op', ['op' => $frame->opcode]);

            return;
        }

        $handler = self::VOICE_OP_HANDLERS[$frame->opcode];
        $this->$handler($frame);
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

        $protocolVersion = $this->resolveDaveProtocolVersion($this->extractProtocolVersion($data->d));
        if ($protocolVersion > 0) {
            if ($this->initializeDaveRuntimeState($protocolVersion)) {
                $this->sendDaveKeyPackage();
            }
        } else {
            $this->daveState->resetProtocolState();
            $this->daveState->setProtocolVersion(0);
        }

        $this->discord->logger->debug('received description packet, vc ready', ['data' => $sd->__debugInfo()]);

        if (! $this->vc->reconnecting) {
            $this->vc->emit('ready', [$this->vc]);
        } else {
            $this->vc->reconnecting = false;
            $this->vc->emit('resumed', [$this->vc]);
            # TODO: check if this can fix the reconnect issue
            //$this->vc->emit('ready', [$this->vc]);
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
        $this->vc->updateSpeakingStatus($speaking);
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

        $rawVersion = $this->extractProtocolVersion(is_array($data->d) ? $data->d : []);
        if ($rawVersion > 0) {
            $this->daveState->setProtocolVersion($this->resolveDaveProtocolVersion($rawVersion));
        }
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
        $userIds = is_array($data->d['user_ids'] ?? null) ? $data->d['user_ids'] : [];
        $this->daveState->addRecognizedUsers($userIds);
        foreach ($userIds as $userId) {
            $this->vc->users[$userId] = $this->discord->getFactory()->part(UserConnected::class, ['user_id' => $userId]);
        }
    }

    /**
     * Handles the event when a client disconnects from the voice server.
     *
     * @param Payload $data
     */
    protected function handleClientDisconnect(Payload $data): void
    {
        $this->discord->logger->debug('received client disconnected packet', ['data' => $data]);
        $this->daveState->removeRecognizedUser($data->d['user_id']);
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
        $this->getCoordinator()->handleDavePrepareTransition($data);
    }

    protected function handleDaveExecuteTransition($data): void
    {
        $this->getCoordinator()->handleDaveExecuteTransition($data);
    }

    protected function handleDaveTransitionReady($data): void
    {
        $this->getCoordinator()->handleDaveTransitionReady($data);
    }

    protected function handleDavePrepareEpoch($data): void
    {
        $this->getCoordinator()->handleDavePrepareEpoch($data);
    }

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

    protected function handleDaveMlsProposals($data): void
    {
        $this->getCoordinator()->handleDaveMlsProposals($data);
    }

    protected function handleDaveMlsCommitWelcome($data): void
    {
        $this->getCoordinator()->handleDaveMlsCommitWelcome($data);
    }

    protected function handleDaveMlsAnnounceCommitTransition($data): void
    {
        $this->getCoordinator()->handleDaveMlsAnnounceCommitTransition($data);
    }

    protected function handleDaveMlsWelcome($data): void
    {
        $this->getCoordinator()->handleDaveMlsWelcome($data);
    }

    protected function handleDaveMlsInvalidCommitWelcome($data): void
    {
        $this->getCoordinator()->handleDaveMlsInvalidCommitWelcome($data);
    }

    /**
     * @param array<mixed> $data
     */
    private function extractProtocolVersion(array $data): int
    {
        return $this->getCoordinator()->extractProtocolVersion($data);
    }

    private function resolveDaveProtocolVersion(int $protocolVersion): int
    {
        return $this->getCoordinator()->resolveDaveProtocolVersion($protocolVersion);
    }

    private function initializeDaveRuntimeState(int $protocolVersion, bool $resetState = false): bool
    {
        return $this->getCoordinator()->initializeDaveRuntimeState($protocolVersion, $resetState);
    }

    private function sendDaveKeyPackage(): void
    {
        $this->getCoordinator()->sendDaveKeyPackage();
    }

    private function recordGatewaySequence(?int $sequence): void
    {
        $this->daveState->recordGatewaySequence($sequence);

        if ($sequence !== null) {
            $this->data['seq'] = $sequence;
        }
    }

    public function handleCloseVoiceDisconnected(Payload $data): void
    {
        $this->discord->logger->debug('Voice disconnected close opcode received.', ['data' => $data]);
    }

    private function resolveDaveGroupId(): int|string|null
    {
        $channelId = $this->vc->channel->id;
        if ($channelId !== null) {
            return $channelId;
        }

        return $this->vc->channel->guild_id ?? null;
    }

    public function getDaveProtocolVersion(): int
    {
        return $this->daveState->protocolVersion;
    }

    public function getDaveState(): DaveState
    {
        return $this->daveState;
    }

    /**
     * Sends a heartbeat to the voice WebSocket.
     */
    public function sendHeartbeat(): void
    {
        $data = ['t' => (int) microtime(true)];
        if ($this->daveState->lastReceivedSequence !== null) {
            $data['seq_ack'] = $this->daveState->lastReceivedSequence;
        }

        $this->send(VoicePayload::new(Op::VOICE_HEARTBEAT, $data));
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

        // Cancel heartbeat timers — both WS's own timer and VoiceClient's timer.
        if (isset($this->heartbeat)) {
            $this->discord->loop->cancelTimer($this->heartbeat);
            unset($this->heartbeat);
        }
        if (null !== $this->vc->heartbeat) {
            $this->discord->loop->cancelTimer($this->vc->heartbeat);
            $this->vc->heartbeat = null;
        }

        // Close UDP socket.
        if (isset($this->vc->udp)) {
            $this->discord->logger->warning('closing UDP client');
            $this->vc->udp->close();
        }

        $this->daveState->close();
        $this->socket->close();

        // Don't reconnect on a critical opcode or if closed by user.
        if (in_array($op, Op::getCriticalVoiceCloseCodes()) || $this?->vc->userClose) {
            $this->discord->logger->warning('received critical opcode - not reconnecting', ['op' => $op, 'reason' => $reason]);
            $this->discord->voice_sessions[$this->vc->channel->guild_id] = null;
            if ($op === Op::CLOSE_INVALID_SESSION) {
                $this->discord->logger->debug('sessions', ['voice_sessions' => $this->discord->voice_sessions]);
            }
            $this->vc->voice_sessions[$this->vc->channel->guild_id] = null;
            // prevent race conditions
            if ($this->vc->ready) {
                $this->vc->close();
            }

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

        // Only a reconnect may resume; initial connects already have the VOICE_STATE_UPDATE session id.
        if ($this->vc->reconnecting && isset($this->data['token'], $this->discord->voice_sessions[$this->vc->channel->guild_id])) {
            $this->handleResume();
            $this->sentLoginFrame = true;
            $this->vc->sentLoginFrame = true;

            return;
        }

        $data = [
            'server_id' => $this->vc->channel->guild_id,
            'user_id' => $this->data['user_id'],
            'token' => $this->data['token'],
            'max_dave_protocol_version' => $this->maxDaveProtocolVersion,
        ];
        if (isset($this->discord->voice_sessions[$this->vc->channel->guild_id])) {
            $this->data['session'] = $this->discord->voice_sessions[$this->vc->channel->guild_id];
            $data['session_id'] = $this->data['session'];
        }

        $payload = VoicePayload::new(Op::VOICE_IDENTIFY, $data);

        $this->discord->logger->debug('sending identify', ['op' => $payload->op]);

        $this->send($payload);
        $this->sentLoginFrame = true;
        $this->vc->sentLoginFrame = true;
    }

    /**
     * Resumes a previously established voice connection.
     */
    protected function handleResume(): void
    {
        $data = [
            'server_id' => $this->vc->channel->guild_id,
            'session_id' => $this->discord->voice_sessions[$this->vc->channel->guild_id],
            'token' => $this->data['token'],
            'max_dave_protocol_version' => $this->maxDaveProtocolVersion,
        ];

        if ($this->daveState->lastReceivedSequence !== null) {
            $data['seq_ack'] = $this->daveState->lastReceivedSequence;
        }

        $payload = VoicePayload::new(
            Op::VOICE_RESUME,
            $data
        );

        $this->discord->logger->debug('sending identify (resume)', ['packet' => $payload->__debugInfo()]);

        $this->send($payload);
    }

    /**
     * Returns the secret key for voice encryption.
     *
     * @internal
     */
    public function getSecretKey(): ?string
    {
        return $this->secretKey;
    }

    /**
     * Returns the raw secret key array.
     *
     * @internal
     */
    public function getRawKey(): ?array
    {
        return $this->rawKey;
    }

    /**
     * Redacts sensitive cryptographic material from debug output.
     */
    public function __debugInfo(): array
    {
        $info = get_object_vars($this);
        if (isset($info['secretKey'])) {
            $info['secretKey'] = '[REDACTED]';
        }
        if (isset($info['rawKey'])) {
            $info['rawKey'] = '[REDACTED]';
        }

        return $info;
    }
}
