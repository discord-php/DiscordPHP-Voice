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

namespace Discord\Tests\Feature\Voice;

use Discord\Discord;
use Discord\Factory\SocketFactory;
use Discord\Voice\Client;
use Discord\Voice\Client\WS;
use Discord\Voice\Dave\BinaryFrame;
use Discord\Voice\Dave\EncryptorHandle;
use Discord\Voice\Dave\Runtime;
use Discord\Voice\Dave\SessionHandle;
use Discord\Voice\Dave\State;
use Discord\Voice\Speaking;
use Discord\WebSockets\Op;
use Discord\WebSockets\Payload;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ratchet\Client\WebSocket;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;

afterEach(function (): void {
    Runtime::reset();
});

// ──────────────────────────────────────────────────────────────
// handleHello
// ──────────────────────────────────────────────────────────────

it('handleHello stores the heartbeat interval and sends an initial heartbeat', function (): void {
    $sentPayloads = [];
    $ws = makeWsForHandlersTest($this, $sentPayloads);

    $hello = new class() {
        public int $heartbeat_interval = 41250;
    };
    setHandlersFactoryReturn($ws, $hello);

    invokeHandlersMethod($ws, 'handleHello', [new Payload(Op::VOICE_HELLO, ['heartbeat_interval' => 41250])]);

    $hbIntervalProp = new \ReflectionProperty(WS::class, 'hbInterval');
    $hbIntervalProp->setAccessible(true);

    expect($hbIntervalProp->getValue($ws))->toBe(41250)
        ->and($ws->vc->heartbeatInterval)->toBe(41250)
        ->and($sentPayloads)->toHaveCount(1);

    $sent = json_decode($sentPayloads[0], true);
    expect($sent['op'])->toBe(Op::VOICE_HEARTBEAT);
});

it('handleHello schedules a periodic heartbeat using the loop', function (): void {
    $sentPayloads = [];
    $ws = makeWsForHandlersTest($this, $sentPayloads);

    $hello = new class() {
        public int $heartbeat_interval = 30000;
    };
    setHandlersFactoryReturn($ws, $hello);

    invokeHandlersMethod($ws, 'handleHello', [new Payload(Op::VOICE_HELLO, ['heartbeat_interval' => 30000])]);

    $log = handlersTestStore($ws, 'periodicTimerLog');

    expect($log['count'])->toBe(1)
        ->and($log['interval'])->toBe(30.0);
});

// ──────────────────────────────────────────────────────────────
// handleReady
// ──────────────────────────────────────────────────────────────

it('handleReady captures the SSRC from the ready payload onto the voice client', function (): void {
    $sentPayloads = [];
    $ws = makeWsForHandlersTest($this, $sentPayloads);

    $ready = new class() {
        public int $ssrc = 987654;
        public string $ip = 'voice.example.invalid';
        public int $port = 50001;
    };
    setHandlersFactoryReturn($ws, $ready);

    installPendingUdpFactory($ws);

    invokeHandlersMethod($ws, 'handleReady', [new Payload(Op::VOICE_READY, [
        'ssrc' => 987654,
        'ip' => 'voice.example.invalid',
        'port' => 50001,
        'modes' => ['aead_aes256_gcm_rtpsize'],
    ])]);

    expect($ws->vc->ssrc)->toBe(987654);
});

it('handleReady triggers IP discovery by dispatching the announced host through SocketFactory', function (): void {
    $sentPayloads = [];
    $ws = makeWsForHandlersTest($this, $sentPayloads);

    $ready = new class() {
        public int $ssrc = 1;
        public string $ip = 'voice.example.invalid';
        public int $port = 12345;
    };
    setHandlersFactoryReturn($ws, $ready);

    $resolverCalls = &installPendingUdpFactory($ws);

    invokeHandlersMethod($ws, 'handleReady', [new Payload(Op::VOICE_READY, [])]);

    // resolveAddress() parses "host:port" then asks the resolver for just the host,
    // proving createClient was invoked with the announced address.
    expect($resolverCalls)->toHaveCount(1)
        ->and($resolverCalls[0])->toBe('voice.example.invalid');
});

// ──────────────────────────────────────────────────────────────
// handleSpeaking
// ──────────────────────────────────────────────────────────────

it('handleSpeaking registers the user payload in speakingStatus and emits speaking events', function (): void {
    $sentPayloads = [];
    $ws = makeWsForHandlersTest($this, $sentPayloads);

    $speaking = makeHandlersSpeaking('12345', 99, true);
    setHandlersFactoryReturn($ws, $speaking);

    invokeHandlersMethod($ws, 'handleSpeaking', [new Payload(Op::VOICE_SPEAKING, [
        'user_id' => '12345',
        'ssrc' => 99,
        'speaking' => 1,
    ])]);

    $events = array_map(fn ($e) => $e[0], iterator_to_array(handlersTestStore($ws, 'emittedEvents')));

    expect(getHandlersSpeakingStatus($ws->vc))->toHaveKey('12345')
        ->and(getHandlersSpeakingStatus($ws->vc)['12345'])->toBe($speaking)
        ->and(getHandlersSsrcMap($ws->vc))->toBe([99 => '12345'])
        ->and($events)->toContain('speaking')
        ->and($events)->toContain('speaking.12345');
});

it('handleSpeaking does not populate ssrcToUserId when ssrc is missing', function (): void {
    $sentPayloads = [];
    $ws = makeWsForHandlersTest($this, $sentPayloads);

    $speaking = makeHandlersSpeaking('u-without-ssrc', null, false);
    setHandlersFactoryReturn($ws, $speaking);

    invokeHandlersMethod($ws, 'handleSpeaking', [new Payload(Op::VOICE_SPEAKING, [
        'user_id' => 'u-without-ssrc',
        'speaking' => 0,
    ])]);

    expect(getHandlersSpeakingStatus($ws->vc))->toHaveKey('u-without-ssrc')
        ->and(getHandlersSsrcMap($ws->vc))->toBeEmpty();
});

// ──────────────────────────────────────────────────────────────
// handleSessionDescription
// ──────────────────────────────────────────────────────────────

it('handleSessionDescription captures the secret key, marks the client ready and emits ready', function (): void {
    $sentPayloads = [];
    $ws = makeWsForHandlersTest($this, $sentPayloads);
    $ws->vc->deaf = true; // skip udp->handleMessages branch
    $ws->vc->reconnecting = false;

    $secretKeyBytes = array_fill(0, 32, 7);
    $sd = new class($secretKeyBytes) {
        public string $mode = 'aead_aes256_gcm_rtpsize';
        public string $secret_key;

        public function __construct(array $bytes)
        {
            $this->secret_key = pack('C*', ...$bytes);
        }

        public function __debugInfo(): array
        {
            return ['mode' => $this->mode, 'secret_key' => '*****'];
        }
    };
    setHandlersFactoryReturn($ws, $sd);

    invokeHandlersMethod($ws, 'handleSessionDescription', [new Payload(Op::VOICE_SESSION_DESCRIPTION, [
        'mode' => 'aead_aes256_gcm_rtpsize',
        'secret_key' => $secretKeyBytes,
        'dave_protocol_version' => 0,
    ])]);

    $rawKeyProp = new \ReflectionProperty(WS::class, 'rawKey');
    $rawKeyProp->setAccessible(true);
    $secretKeyProp = new \ReflectionProperty(WS::class, 'secretKey');
    $secretKeyProp->setAccessible(true);

    expect($ws->vc->ready)->toBeTrue()
        ->and($ws->mode)->toBe('aead_aes256_gcm_rtpsize')
        ->and($rawKeyProp->getValue($ws))->toBe($secretKeyBytes)
        ->and($secretKeyProp->getValue($ws))->toBe($sd->secret_key);

    $emitted = iterator_to_array(handlersTestStore($ws, 'emittedEvents'));
    $events = array_map(fn ($e) => $e[0], $emitted);
    expect($events)->toContain('ready');
});

it('handleSessionDescription falls back to aead_aes256_gcm_rtpsize when the offered mode does not match', function (): void {
    $sentPayloads = [];
    $ws = makeWsForHandlersTest($this, $sentPayloads);
    $ws->vc->deaf = true;
    $ws->vc->reconnecting = false;

    $sd = new class() {
        public string $mode = 'xsalsa20_poly1305_unsupported';
        public string $secret_key = 'some-binary-key';

        public function __debugInfo(): array
        {
            return ['mode' => $this->mode];
        }
    };
    setHandlersFactoryReturn($ws, $sd);

    invokeHandlersMethod($ws, 'handleSessionDescription', [new Payload(Op::VOICE_SESSION_DESCRIPTION, [
        'mode' => 'xsalsa20_poly1305_unsupported',
        'secret_key' => array_fill(0, 32, 1),
        'dave_protocol_version' => 0,
    ])]);

    expect($ws->mode)->toBe('aead_aes256_gcm_rtpsize');
});

it('handleSessionDescription resets DAVE protocol state when dave_protocol_version is 0', function (): void {
    $sentPayloads = [];
    $ws = makeWsForHandlersTest($this, $sentPayloads);
    $ws->vc->deaf = true;
    $ws->vc->reconnecting = false;

    $state = getHandlersDaveState($ws);
    $state->setProtocolVersion(1);
    $state->passthroughMode = false;

    $sd = new class() {
        public string $mode = 'aead_aes256_gcm_rtpsize';
        public string $secret_key = 'k';

        public function __debugInfo(): array
        {
            return [];
        }
    };
    setHandlersFactoryReturn($ws, $sd);

    invokeHandlersMethod($ws, 'handleSessionDescription', [new Payload(Op::VOICE_SESSION_DESCRIPTION, [
        'mode' => 'aead_aes256_gcm_rtpsize',
        'secret_key' => array_fill(0, 32, 1),
        'dave_protocol_version' => 0,
    ])]);

    expect($state->protocolVersion)->toBe(0)
        ->and($state->passthroughMode)->toBeTrue();
});

it('handleSessionDescription sends a DAVE key package when DAVE is enabled', function (): void {
    $sentPayloads = [];
    $ws = makeWsForHandlersTest($this, $sentPayloads);
    $ws->vc->deaf = true;
    $ws->vc->reconnecting = false;

    $state = getHandlersDaveState($ws);
    $state->replaceSession(new SessionHandle(new \stdClass()));
    $state->replaceEncryptor(new EncryptorHandle(new \stdClass()));

    Runtime::configureCallbacks(
        keyPackageCallback: fn (SessionHandle $session): ?string => 'key-package'
    );

    $sd = new class() {
        public string $mode = 'aead_aes256_gcm_rtpsize';
        public string $secret_key = 'k';

        public function __debugInfo(): array
        {
            return [];
        }
    };
    setHandlersFactoryReturn($ws, $sd);

    invokeHandlersMethod($ws, 'handleSessionDescription', [new Payload(Op::VOICE_SESSION_DESCRIPTION, [
        'mode' => 'aead_aes256_gcm_rtpsize',
        'secret_key' => array_fill(0, 32, 1),
        'dave_protocol_version' => 1,
    ])]);

    expect($sentPayloads)->not->toBeEmpty();
    $frame = BinaryFrame::fromClientPayload($sentPayloads[0]);

    expect($frame)->not->toBeNull()
        ->and($frame?->opcode)->toBe(Op::VOICE_DAVE_MLS_KEY_PACKAGE)
        ->and($frame?->payload)->toBe('key-package')
        ->and($state->keyPackageSent)->toBeTrue();
});

it('handleSessionDescription emits resumed (not ready) when the voice client is reconnecting', function (): void {
    $sentPayloads = [];
    $ws = makeWsForHandlersTest($this, $sentPayloads);
    $ws->vc->deaf = true;
    $ws->vc->reconnecting = true;

    $sd = new class() {
        public string $mode = 'aead_aes256_gcm_rtpsize';
        public string $secret_key = 'k';

        public function __debugInfo(): array
        {
            return [];
        }
    };
    setHandlersFactoryReturn($ws, $sd);

    invokeHandlersMethod($ws, 'handleSessionDescription', [new Payload(Op::VOICE_SESSION_DESCRIPTION, [
        'mode' => 'aead_aes256_gcm_rtpsize',
        'secret_key' => array_fill(0, 32, 1),
        'dave_protocol_version' => 0,
    ])]);

    $emitted = iterator_to_array(handlersTestStore($ws, 'emittedEvents'));
    $events = array_map(fn ($e) => $e[0], $emitted);

    expect($events)->toContain('resumed')
        ->and($events)->not->toContain('ready')
        ->and($ws->vc->reconnecting)->toBeFalse();
});

// ──────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────

/**
 * Build a WS wired with a Discord (NullLogger + mocked loop + mockable factory),
 * a vc Client mock that records emit() calls, a fresh DAVE State and a mocked socket.
 *
 * @param array<int,string> $sentPayloads Reference to collect raw send() strings.
 */
function makeWsForHandlersTest(TestCase $test, array &$sentPayloads): WS
{
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();

    $discord = invokeHandlersMethod($test, 'getMockBuilder', [Discord::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['factory'])
        ->getMock();

    $loggerProp = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProp->setAccessible(true);
    $loggerProp->setValue($discord, new NullLogger());

    $loop = invokeHandlersMethod($test, 'getMockBuilder', [LoopInterface::class])->getMock();
    $timer = invokeHandlersMethod($test, 'getMockBuilder', [TimerInterface::class])->getMock();
    $periodicTimerLog = new \ArrayObject(['count' => 0, 'interval' => null]);
    $loop->method('addPeriodicTimer')->willReturnCallback(function ($interval) use ($timer, $periodicTimerLog) {
        $periodicTimerLog['count'] = ((int) $periodicTimerLog['count']) + 1;
        $periodicTimerLog['interval'] = (float) $interval;

        return $timer;
    });
    handlersTestStore($ws, 'periodicTimerLog', $periodicTimerLog);

    $loopProp = new \ReflectionProperty(Discord::class, 'loop');
    $loopProp->setAccessible(true);
    $loopProp->setValue($discord, $loop);

    $vc = invokeHandlersMethod($test, 'getMockBuilder', [Client::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['emit'])
        ->getMock();
    $emittedEvents = new \ArrayObject([]);
    $vc->method('emit')->willReturnCallback(function (string $event, array $args = []) use ($emittedEvents): void {
        $emittedEvents[] = [$event, $args];
    });
    handlersTestStore($ws, 'emittedEvents', $emittedEvents);
    $vc->ssrc = null;
    $vc->ready = false;
    $vc->reconnecting = false;
    $vc->deaf = false;
    $vc->users = [];

    $speakingStatusProp = new \ReflectionProperty(\Discord\Voice\VoiceClient::class, 'speakingStatus');
    $speakingStatusProp->setAccessible(true);
    $speakingStatusProp->setValue($vc, []);

    $ssrcToUserIdProp = new \ReflectionProperty(\Discord\Voice\VoiceClient::class, 'ssrcToUserId');
    $ssrcToUserIdProp->setAccessible(true);
    $ssrcToUserIdProp->setValue($vc, []);

    $state = new State();
    $state->setIdentity('1', 99);

    $socket = invokeHandlersMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send', 'close'])
        ->getMock();
    $socket->method('send')->willReturnCallback(function (mixed $payload) use (&$sentPayloads): void {
        $sentPayloads[] = $payload instanceof \Ratchet\RFC6455\Messaging\Frame
            ? $payload->getPayload()
            : $payload;
    });

    $ws->vc = $vc;

    $discordProp = new \ReflectionProperty(WS::class, 'discord');
    $discordProp->setAccessible(true);
    $discordProp->setValue($ws, $discord);

    $daveStateProp = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProp->setAccessible(true);
    $daveStateProp->setValue($ws, $state);

    $socketProp = new \ReflectionProperty(WS::class, 'socket');
    $socketProp->setAccessible(true);
    $socketProp->setValue($ws, $socket);

    return $ws;
}

/**
 * Side-channel storage so tests can recover recorders without polluting WS or its mocks
 * with dynamic properties (deprecated in PHP 8.2+).
 *
 * @var \WeakMap<WS,array<string,\ArrayObject>>|null
 */
function handlersTestStore(WS $ws, string $key, ?\ArrayObject $value = null): ?\ArrayObject
{
    static $store = null;
    $store ??= new \WeakMap();

    $bag = $store[$ws] ?? [];
    if ($value !== null) {
        $bag[$key] = $value;
        $store[$ws] = $bag;

        return $value;
    }

    return $bag[$key] ?? null;
}

function getHandlersDiscord(WS $ws): Discord
{
    $prop = new \ReflectionProperty(WS::class, 'discord');
    $prop->setAccessible(true);

    return $prop->getValue($ws);
}

function getHandlersDaveState(WS $ws): State
{
    $prop = new \ReflectionProperty(WS::class, 'daveState');
    $prop->setAccessible(true);

    return $prop->getValue($ws);
}

function getHandlersSsrcMap(object $vc): array
{
    $prop = new \ReflectionProperty(\Discord\Voice\VoiceClient::class, 'ssrcToUserId');
    $prop->setAccessible(true);

    return $prop->getValue($vc);
}

function getHandlersSpeakingStatus(object $vc): mixed
{
    $prop = new \ReflectionProperty(\Discord\Voice\VoiceClient::class, 'speakingStatus');
    $prop->setAccessible(true);

    return $prop->getValue($vc);
}

/**
 * Force the next call to $discord->factory(...) to return $value.
 */
function setHandlersFactoryReturn(WS $ws, object $value): void
{
    /** @var \PHPUnit\Framework\MockObject\MockObject $discord */
    $discord = getHandlersDiscord($ws);
    $discord->method('factory')->willReturn($value);
}

/**
 * Install a SocketFactory whose createClient() returns a never-resolving promise,
 * recording the host names its resolver was asked to look up.
 *
 * @return array<int,string> Reference array of host names passed to the resolver.
 */
function &installPendingUdpFactory(WS $ws): array
{
    $calls = [];
    $factory = (new \ReflectionClass(SocketFactory::class))->newInstanceWithoutConstructor();

    $deferred = new Deferred();
    $resolver = new class($deferred, $calls) {
        /** @param array<int,string> $calls */
        public function __construct(private Deferred $deferred, private array &$calls)
        {
        }

        public function resolve(string $host)
        {
            $this->calls[] = $host;

            return $this->deferred->promise();
        }
    };

    $loopProp = new \ReflectionProperty(\React\Datagram\Factory::class, 'loop');
    $loopProp->setAccessible(true);
    $loopProp->setValue($factory, null);

    $resolverProp = new \ReflectionProperty(\React\Datagram\Factory::class, 'resolver');
    $resolverProp->setAccessible(true);
    $resolverProp->setValue($factory, $resolver);

    $udpfacProp = new \ReflectionProperty(WS::class, 'udpfac');
    $udpfacProp->setAccessible(true);
    $udpfacProp->setValue($ws, $factory);

    return $calls;
}

/**
 * @param array<int,mixed> $arguments
 */
function invokeHandlersMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}

function makeHandlersSpeaking(string $userId, ?int $ssrc, bool $isSpeaking): Speaking
{
    $speaking = (new \ReflectionClass(Speaking::class))->newInstanceWithoutConstructor();
    $attrs = new \ReflectionProperty(Speaking::class, 'attributes');
    $attrs->setAccessible(true);
    $attrs->setValue($speaking, [
        'user_id' => $userId,
        'ssrc' => $ssrc,
        'speaking' => $isSpeaking ? 1 : 0,
        'delay' => 0,
    ]);

    return $speaking;
}
