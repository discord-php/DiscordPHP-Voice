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

namespace Discord\Voice;

use Discord\Discord;
use Discord\Voice\Dave\MediaCryptoService;
use Discord\Voice\Concerns\ManagesPlayback;
use Discord\Voice\Concerns\ManagesReceiving;
use Discord\Voice\Concerns\ManagesRecording;
use Discord\Voice\Ogg\Buffer as RealBuffer;
use Discord\Helpers\Collection;
use Discord\Helpers\ExCollectionInterface;
use Discord\Parts\Channel\Channel;
use Discord\Parts\WebSockets\VoiceStateUpdate;
use Discord\Voice\Receive\User;
use Discord\Voice\Rtp\Packet;
use Discord\Voice\Rtp\UDP;
use Discord\Voice\Gateway\WS;
use Discord\Voice\Processes\Ffmpeg;
use Discord\Voice\Processes\OpusDecoderInterface;
use Discord\Voice\Processes\OpusFfi;
use Discord\Voice\Recording\RecordingFormat;
use Discord\Voice\Recording\WavWriter;
use Discord\WebSockets\Op;
use Discord\WebSockets\Payload;
use Discord\WebSockets\VoicePayload;
use Evenement\EventEmitterTrait;
use Ratchet\Client\WebSocket;
use React\ChildProcess\Process;
use React\Datagram\Socket;
use React\Dns\Config\Config;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;

/**
 * The Discord voice client.
 *
 * @since 10.19.0
 */
class VoiceClient
{
    use EventEmitterTrait;
    use ManagesPlayback;
    use ManagesReceiving;
    use ManagesRecording;

    /** Not speaking. */
    public const NOT_SPEAKING = 0;
    /** Normal transmission of voice audio. */
    public const MICROPHONE = 1 << 0;
    /** Transmission of context audio for video, no speaking indicator. */
    public const SOUNDSHARE = 1 << 1;
    /** Priority speaker, lowering audio of other speakers. */
    public const PRIORITY_SPEAKER = 1 << 2;

    /**
     * Allowed URL schemes for playFile().
     */
    private const ALLOWED_URL_SCHEMES = ['https'];

    /**
     * Maximum number of concurrent voice decoders to prevent resource exhaustion.
     */
    private const MAX_DECODERS = 25;

    /**
     * Is the voice client ready?
     *
     * @var bool Whether the voice client is ready.
     */
    public bool $ready = false;

    /**
     * The voice WebSocket instance.
     *
     * @var WebSocket|null The voice WebSocket client.
     */
    public ?WebSocket $ws;

    /**
     * The UDP client instance.
     *
     * @var null|Socket|\Discord\Voice\Rtp\UDP
     */
    public ?UDP $udp;

    /**
     * The Opus Decoder instance.
     *
     * @var OpusDecoderInterface|null The Opus Decoder instance used for decoding audio.
     */
    public ?OpusDecoderInterface $opusdecoder = null;

    /**
     * Per-SSRC OpusFfi decoder instances. Each speaker gets their own persistent
     * decoder so inter-frame codec state is never shared or reset between users.
     *
     * @var array<int, OpusFfi>
     */
    protected array $ffiDecoders = [];

    /**
     * The Voice WebSocket endpoint.
     *
     * @var string|null The endpoint the Voice WebSocket and UDP client will connect to.
     */
    public ?string $endpoint;

    /**
     * The UDP heartbeat interval.
     *
     * @var int|null How often we send a heartbeat packet.
     */
    public ?int $heartbeatInterval = null;

    /**
     * The Voice WebSocket heartbeat timer.
     *
     * @var TimerInterface|null The heartbeat periodic timer.
     */
    public ?TimerInterface $heartbeat = null;

    /**
     * The SSRC value.
     *
     * @var int|null The SSRC value used for RTP.
     */
    public ?int $ssrc;

    /**
     * The sequence of audio packets being sent.
     *
     * @var int The sequence of audio packets.
     */
    public ?int $seq = 0;

    /**
     * Independent 32-bit nonce counter used for AES-256-GCM encryption.
     * Increments separately from the 16-bit $seq so nonces never repeat after
     * a sequence rollover (~21 min at 50 pkt/s).
     *
     * @var int
     */
    public int $nonce = 0;

    /**
     * The timestamp of the last packet.
     *
     * @var int The timestamp the last packet was constructed.
     */
    public ?int $timestamp = 0;

    /**
     * @var int
     */
    public int $speaking = self::NOT_SPEAKING;

    /**
     * Whether the voice client is currently paused.
     *
     * @var bool
     */
    public bool $paused = false;

    /**
     * Have we sent the login frame yet?
     *
     * @var bool Whether we have sent the login frame.
     */
    public bool $sentLoginFrame = false;

    /**
     * The time we started sending packets.
     *
     * @var float|int|null The time we started sending packets.
     */
    public null|float|int $startTime;

    /**
     * The size of audio frames, in milliseconds.
     *
     * @var int The size of audio frames.
     */
    public int $frameSize = 20;

    /**
     * Collection of the status of people speaking.
     *
     * @var ExCollectionInterface<Speaking> Status of people speaking.
     */
    protected $speakingStatus;

    /**
     * O(1) map from SSRC to user ID, kept in sync with speakingStatus.
     *
     * @var array<int, string>
     */
    protected array $ssrcToUserId = [];

    /**
     * Collection of voice decoders.
     *
     * @var array<int, Process> Voice decoders.
     */
    public array $voiceDecoders = [];

    /**
     * Voice audio recieve streams.
     *
     * @deprecated 10.5.0 Use receiveStreams instead.
     *
     * @var array<ReceiveStream>|null Voice audio recieve streams.
     */
    public ?array $recieveStreams;

    /**
     * Voice audio receive streams.
     *
     * @var array<ReceiveStream>|null Voice audio recieve streams.
     */
    public ?array $receiveStreams;

    /**
     * The volume the audio will be encoded with.
     *
     * @var int The volume that the audio will be encoded in.
     */
    protected int $volume = 100;

    /**
     * The audio application to encode with.
     *
     * Available: voip, audio (default), lowdelay
     *
     * @var string The audio application.
     */
    protected string $audioApplication = 'audio';

    /**
     * The bitrate to encode with.
     *
     * @var int Encoding bitrate.
     */
    protected int $bitrate = 128000;

    /**
     * Is the voice client reconnecting?
     *
     * @var bool Whether the voice client is reconnecting.
     */
    public bool $reconnecting = false;

    /**
     * Is the voice client being closed by user?
     *
     * @var bool Whether the voice client is being closed by user.
     */
    public bool $userClose = false;

    /**
     * The Config for DNS Resolver.
     *
     * @var Config|string|null
     */
    public null|string|Config $dnsConfig;

    /**
     * readopus Timer.
     *
     * @var TimerInterface|null Timer
     */
    public ?TimerInterface $readOpusTimer = null;

    /**
     * Audio Buffer.
     *
     * @var RealBuffer|null The Audio Buffer
     */
    public null|RealBuffer $buffer;

    /**
     * Current clients connected to the voice chat.
     *
     * @var array
     */
    public array $clientsConnected = [];

    /**
     * @var TimerInterface
     */
    public $monitorProcessTimer;

    /**
     * Users in the current voice channel.
     *
     * @var array<User> Users in the current voice channel.
     */
    public array $users;

    /**
     * Time in which the streaming started.
     *
     * @var int
     */
    public int $streamTime = 0;

    /**
     * Silence Frame Remain Count.
     *
     * @var int Amount of silence frames remaining.
     */
    protected $silenceRemaining = 5;

    /**
     * Whether the current voice client is enabled to record audio.
     *
     * @var bool
     */
    protected bool $shouldRecord = false;

    /**
     * Active WavWriter instances keyed by SSRC (populated when record() is called
     * with RecordingFormat::WAV and an output path callback).
     *
     * @var array<int, WavWriter>
     */
    protected array $recordingWriters = [];

    /**
     * Active ffmpeg OGG encoder processes keyed by SSRC (populated when record()
     * is called with RecordingFormat::OGG and an output path callback).
     *
     * @var array<int, Process>
     */
    protected array $recordingProcesses = [];

    /**
     * Open file handles for raw PCM output keyed by SSRC (populated when record()
     * is called with RecordingFormat::PCM and an output path callback).
     *
     * @var array<int, resource>
     */
    protected array $recordingPcmHandles = [];

    /**
     * The active recording format, set when record() is called with a format.
     */
    protected ?RecordingFormat $recordingFormat = null;

    /**
     * Callback that returns the output file path for a given user ID string,
     * set when record() is called with a non-null $outputPath.
     *
     * @var \Closure(string): string|null
     */
    protected ?\Closure $recordingOutputPath = null;

    /**
     * DAVE media-layer crypto service (lazy-initialized on first use).
     */
    private ?MediaCryptoService $mediaCrypto = null;

    /**
     * Allows read-only access to selected protected properties from outside the class.
     *
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        static $allowed = ['speakingStatus', 'ssrcToUserId'];

        if (in_array($name, $allowed, true)) {
            return $this->{$name};
        }

        return null;
    }

    /**
     * Constructs the Voice client instance.
     *
     * @param Discord       $discord         The Discord instance.
     * @param Channel       $channel
     * @param string[]      &$voice_sessions
     * @param array         $data
     * @param bool          $deaf            Default: false
     * @param bool          $mute            Default: false
     * @param Deferred|null $deferred
     * @param Manager|null  $manager
     */
    public function __construct(
        public Discord $discord,
        public Channel $channel,
        public array &$voice_sessions,
        public array $data = [],
        public bool $deaf = false,
        public bool $mute = false,
        protected ?Deferred $deferred = null,
        public ?Manager &$manager = null,
        protected bool $shouldBoot = true
    ) {
        $this->deaf = $this->data['deaf'] ?? false;
        $this->mute = $this->data['mute'] ?? false;

        $this->data['user_id'] = $this->discord->id;
        $this->data['deaf'] = $this->deaf;
        $this->data['mute'] = $this->mute;
        $this->data['session'] = $this->data['session'] ?? null;

        $this->speakingStatus = Collection::for(Speaking::class, 'ssrc');

        if (extension_loaded('ffi')) {
            try {
                $this->setDecoder(OpusFfi::new());
            } catch (\Throwable $e) {
                // libopus not available; Opus FFI decoder will not be used
            }
        }

        if ($this->shouldBoot) {
            $this->boot();
        }
    }

    /**
     * Starts the voice client.
     *
     * @return bool
     */
    public function start(): bool
    {
        if (! Ffmpeg::checkForFFmpeg()) {
            return false;
        }

        WS::make($this, $this->discord, $this->data);

        return true;
    }

    /**
     * Checks if an executable exists on the system.
     *
     * @param  string      $executable
     * @return string|null
     *
     * @deprecated 10.6.0 Use ProcessAbstract::checkForExecutable() instead.
     */
    public static function checkForExecutable(string $executable): ?string
    {
        $systemOs = substr(PHP_OS, 0, 3);
        $which = 'command -v';
        if (strtoupper($systemOs) === 'WIN') {
            $which = 'where';
        }

        $shellExecutable = shell_exec("$which ".escapeshellarg($executable));
        if ($shellExecutable === false) {
            // Unable to establish pipe
            return null;
        }
        if ($shellExecutable === null) {
            // Error or the command produced no output
            return null;
        }
        $executable = rtrim((string) explode(PHP_EOL, $shellExecutable)[0]);

        return is_executable($executable) ? $executable : null;
    }

    /**
     * Switches voice channels.
     *
     * @param null|Channel $channel The channel to switch to.
     *
     * @throws \InvalidArgumentException
     */
    public function switchChannel(?Channel $channel): self
    {
        if (isset($channel) && ! $channel->isVoiceBased()) {
            throw new \InvalidArgumentException("Channel must be a voice channel to be able to switch, given type {$channel->type}.");
        }

        // We allow the user to switch to null, which will disconnect them from the voice channel.
        if (! isset($channel)) {
            $this->userClose = true;
        } else {
            $this->channel = $channel;
        }

        $this->mainSend(VoicePayload::new(
            Op::OP_UPDATE_VOICE_STATE,
            [
                'guild_id' => $this->channel->guild_id,
                'channel_id' => $channel?->id,
                'self_mute' => $this->mute,
                'self_deaf' => $this->deaf,
            ],
        ));

        return $this;
    }

    /**
     * Disconnects the discord from the current voice channel.
     *
     * @return \Discord\Voice\VoiceClient
     */
    public function disconnect(): static
    {
        $this->switchChannel(null);

        return $this;
    }

    /**
     * Sends a message to the main websocket.
     *
     * @param Payload $data The data to send to the main WebSocket.
     */
    protected function mainSend($data): void
    {
        $this->discord->send($data);
    }

    /**
     * Changes your mute and deaf value.
     *
     * @param bool $mute Whether you should be muted.
     * @param bool $deaf Whether you should be deaf.
     *
     * @throws \RuntimeException
     */
    public function setMuteDeaf(bool $mute, bool $deaf): void
    {
        if (! $this->ready) {
            throw new \RuntimeException('The voice client must be ready before you can set mute or deaf.');
        }

        $this->mute = $mute;
        $this->deaf = $deaf;

        $this->mainSend(VoicePayload::new(
            Op::OP_UPDATE_VOICE_STATE,
            [
                'guild_id' => $this->channel->guild_id,
                'channel_id' => $this->channel->id,
                'self_mute' => $mute,
                'self_deaf' => $deaf,
            ],
        ));

        $this->udp->removeListener('message', [$this, 'handleAudioData']);

        if (! $deaf) {
            $this->udp->on('message', [$this, 'handleAudioData']);
        }
    }

    /**
     * Closes the voice client.
     *
     * @throws \RuntimeException
     */
    public function close(): void
    {
        if (! $this->ready) {
            throw new \RuntimeException('Voice Client is not connected.');
        }

        if ($this->speaking) {
            $this->stop();
            $this->setSpeaking(self::NOT_SPEAKING);
        }

        $this->ready = false;

        // Close processes for audio encoding
        if (count($this->voiceDecoders) > 0) {
            foreach ($this->voiceDecoders as $decoder) {
                $decoder->close();
            }
        }

        if (count($this?->receiveStreams ?? []) > 0) {
            foreach ($this->receiveStreams as $stream) {
                $stream->close();
            }
        }

        if (count($this->speakingStatus) > 0) {
            foreach ($this->speakingStatus as $ss) {
                $this->removeDecoder($ss);
            }
        }

        // Only disconnect if we weren't disconnected by discord
        if (! $this->udp->isClosed()) {
            $this->disconnect();
        }

        $this->userClose = true;
        $this->ws->close();
        $this->udp->close();

        $this->heartbeatInterval = null;

        if (null !== $this->heartbeat) {
            $this->discord->getLoop()->cancelTimer($this->heartbeat);
            $this->heartbeat = null;
        }

        $this->seq = 0;
        $this->timestamp = 0;
        $this->sentLoginFrame = false;
        $this->startTime = null;
        $this->streamTime = 0;
        $this->speakingStatus = Collection::for(Speaking::class, 'ssrc');
        $this->ssrcToUserId = [];

        $this->emit('close');
    }

    /**
     * Handles a voice state update.
     * NOTE: This object contains the data as the VoiceStateUpdate Part.
     * @see \Discord\Parts\WebSockets\VoiceStateUpdate
     *
     * @param VoiceStateUpdate $data The WebSocket data.
     */
    public function handleVoiceStateUpdate(object $data): void
    {
        $ss = $this->speakingStatus->get('user_id', $data->user_id);

        if (null === $ss) {
            return; // not in our channel
        }

        if ($data->channel_id == $this->channel->id) {
            return; // ignore, just a mute/deaf change
        }

        $this->removeDecoder($ss);
    }

    /**
     * Handles a voice server change.
     *
     * @param array $data New voice server information.
     */
    public function handleVoiceServerChange(array $data = []): void
    {
        $this->discord->getLogger()->debug('voice server has changed, dynamically changing servers in the background', ['data' => $data]);
        $this->reconnecting = true;
        $this->sentLoginFrame = false;
        $this->pause();

        $this->close();

        $this->on('resumed', function () {
            $this->discord->getLogger()->debug('voice client resumed');
            $this->unpause();
            $this->speaking = self::NOT_SPEAKING;
            $this->setSpeaking(self::MICROPHONE);
        });

        $data = array_merge($this->data, $data);
        $this->data['token'] = $data['token']; // set the token if it changed
        $this->endpoint = str_replace([':80', ':443'], '', $data['endpoint']);

        WS::make($this, $this->discord, $data);
    }

    /**
     * Encrypts an outgoing Opus frame using DAVE when enabled.
     */
    public function encryptDaveFrame(string $frame): string
    {
        if (! isset($this->udp?->ws)) {
            return $frame;
        }

        $this->mediaCrypto ??= new MediaCryptoService($this->udp->ws->getDaveState(), $this->discord->getLogger());

        return $this->mediaCrypto->encrypt($frame, $this->ssrc);
    }

    /**
     * Decrypts an incoming Opus frame using DAVE when enabled.
     */
    public function decryptDaveFrame(string $frame, ?Packet $packet = null): string|false
    {
        if (! isset($this->udp?->ws)) {
            return $frame;
        }

        $this->mediaCrypto ??= new MediaCryptoService($this->udp->ws->getDaveState(), $this->discord->getLogger());

        $userId = $packet !== null ? $this->resolveDaveRemoteUserId($packet) : null;

        return $this->mediaCrypto->decrypt($frame, $userId, $packet?->getSSRC());
    }

    /** Maps `$packet`'s SSRC to a known remote user id, or null when the SSRC is not yet mapped. */
    private function resolveDaveRemoteUserId(Packet $packet): ?string
    {
        return $this->ssrcToUserId[$packet->getSSRC()] ?? null;
    }

    /**
     * Returns whether the voice client is ready.
     *
     * @return bool Whether the voice client is ready.
     */
    public function isReady(): bool
    {
        return $this->ready;
    }

    /**
     * Creates a new voice client instance statically.
     *
     * @param \Discord\Discord               $discord
     * @param \Discord\Parts\Channel\Channel $channel
     * @param array                          $data
     * @param bool                           $deaf
     * @param bool                           $mute
     * @param null|Deferred                  $deferred
     * @param null|Manager                   $manager
     * @param bool                           $shouldBoot Whether the client should boot immediately.
     *
     * @return \Discord\Voice\Client
     */
    public static function make(): self
    {
        return new static(...func_get_args());
    }

    /**
     * Boots the voice client and sets up event listeners.
     *
     * @return bool
     */
    public function boot(): bool
    {
        return $this->once('ready', function () {
            $this->discord->getLogger()->info('voice client is ready');
            if ($this->manager !== null &&
                isset($this->manager->clients[$this->channel->guild_id]) &&
                $this->manager->clients[$this->channel->guild_id] !== $this) {
                $this->manager->clients[$this->channel->guild_id]->disconnect();
            }

            if ($this->manager !== null) {
                $this->manager->clients[$this->channel->guild_id] = $this;
            }

            $this->setBitrate($this->channel->bitrate);

            $this->discord->getLogger()->info('set voice client bitrate', ['bitrate' => $this->channel->bitrate]);
            $this->deferred->resolve($this);
        })
        ->once('error', function ($e) {
            $this->discord->getLogger()->error('error initializing voice client', ['e' => $e->getMessage()]);
            if ($this->manager !== null) {
                unset($this->manager->clients[$this->channel->guild_id]);
            }
            $this->deferred->reject($e);
        })
        ->once('close', function () {
            $this->discord->getLogger()->warning('voice client closed');
            if ($this->manager !== null) {
                unset($this->manager->clients[$this->channel->guild_id]);
            }
            $this->deferred->reject(new \RuntimeException('Voice client closed.'));
        })
        ->start();
    }

    /**
     * Merges gateway connection data into this client and boots it once
     * `token`, `endpoint`, `session` and `dnsConfig` are all present.
     *
     * @param array<string, mixed> $data
     *
     * @return $this
     */
    public function setData(array $data): self
    {
        $this->data = array_merge($this->data, $data);

        if (isset($this->data['token'], $this->data['endpoint'], $this->data['session'], $this->data['dnsConfig'])) {
            $this->endpoint = str_replace([':80', ':443'], '', $this->data['endpoint']);
            $this->dnsConfig = $this->data['dnsConfig'];
            $this->data['user_id'] ??= $this->discord->id;
            $this->boot();
        }

        return $this;
    }

    /**
     * Sets the Opus decoder.
     *
     * @param OpusDecoderInterface|null $opusdecoder The Opus decoder to set.
     */
    public function setDecoder(?OpusDecoderInterface $opusdecoder = null): void
    {
        $this->opusdecoder = $opusdecoder;
    }
}
