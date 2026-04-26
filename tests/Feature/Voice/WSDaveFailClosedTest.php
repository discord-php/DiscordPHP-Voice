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
use Discord\Voice\Client\Packet;
use Discord\Voice\Client\UDP;
use Discord\Voice\Client\WS;
use Discord\Voice\Dave\Runtime;
use Discord\Voice\Dave\State;
use Discord\Voice\VoiceClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Ratchet\Client\WebSocket;

afterEach(function (): void {
    Runtime::reset();
});

// ---------------------------------------------------------------------------
// Scenario 1: DAVE initialization failure — fail-closed behavior
// ---------------------------------------------------------------------------

/**
 * Documents EXPECTED (fail-closed) behavior: when DAVE session creation fails,
 * the voice WebSocket is closed to prevent plaintext transmission.
 */
it('handleDavePrepareEpoch closes the socket when DAVE session creation fails (fail-closed)', function (): void {
    Runtime::configureCallbacks(createSessionCallback: fn (?string $authSessionId): ?\Discord\Voice\Dave\SessionHandle => null);

    $closeCalled = false;
    [$ws] = makeWsForDaveFailClosedTest($this, function (string $payload): void {
    }, $closeCalled);

    $data = (object) ['d' => ['epoch' => 1, 'dave_protocol_version' => 1]];
    invokeWsDaveFailClosedMethod($ws, 'handleDavePrepareEpoch', [$data]);

    // Fail-closed: socket.close() is called when DAVE session creation fails.
    expect($closeCalled)->toBeTrue();
});

/**
 * After a DAVE init failure the state is reset via resetProtocolState(),
 * which sets passthroughMode=true as part of the teardown before the socket
 * is closed.
 */
it('handleDavePrepareEpoch resets passthroughMode=true via resetProtocolState before closing', function (): void {
    Runtime::configureCallbacks(createSessionCallback: fn (?string $authSessionId): ?\Discord\Voice\Dave\SessionHandle => null);

    [$ws, $state] = makeWsForDaveFailClosedTest($this, function (string $payload): void {
    });
    $state->passthroughMode = false; // simulate previously active DAVE

    $data = (object) ['d' => ['epoch' => 1, 'dave_protocol_version' => 1]];
    invokeWsDaveFailClosedMethod($ws, 'handleDavePrepareEpoch', [$data]);

    expect($state->passthroughMode)->toBeTrue();
});

it('handleDavePrepareEpoch logs an error message when DAVE initialization fails', function (): void {
    Runtime::configureCallbacks(createSessionCallback: fn (?string $authSessionId): ?\Discord\Voice\Dave\SessionHandle => null);

    $logs = [];
    $ws = makeWsDaveFailClosedWithLogger($this, $logs);

    $data = (object) ['d' => ['epoch' => 1, 'dave_protocol_version' => 1]];
    invokeWsDaveFailClosedMethod($ws, 'handleDavePrepareEpoch', [$data]);

    $allText = implode(' ', $logs);
    expect($allText)->toContain('fail-closed');
});

// ---------------------------------------------------------------------------
// Scenario 2: Passthrough mode boundaries
// ---------------------------------------------------------------------------

it('State passthroughMode is true when protocolVersion is 0 (initial safe state before DAVE negotiation)', function (): void {
    $state = new State();
    $state->setProtocolVersion(0);

    expect($state->passthroughMode)->toBeTrue();
});

it('State passthroughMode is false when protocolVersion is 1 (DAVE encryption is active)', function (): void {
    $state = new State();
    $state->setProtocolVersion(1);

    expect($state->passthroughMode)->toBeFalse();
});

it('handleDaveExecuteTransition to v1 disables passthroughMode, making DAVE encryption required', function (): void {
    [$ws, $state] = makeWsForDaveFailClosedTest($this, function (string $payload): void {
    });
    $state->prepareTransition(3, 1);

    $data = (object) ['d' => ['transition_id' => 3]];
    invokeWsDaveFailClosedMethod($ws, 'handleDaveExecuteTransition', [$data]);

    expect($state->passthroughMode)->toBeFalse()
        ->and($state->protocolVersion)->toBe(1);
});

it('handleDaveExecuteTransition to v0 restores passthroughMode=true (DAVE encryption no longer required)', function (): void {
    [$ws, $state] = makeWsForDaveFailClosedTest($this, function (string $payload): void {
    });
    $state->setProtocolVersion(1); // DAVE was previously active
    $state->prepareTransition(4, 0);

    $data = (object) ['d' => ['transition_id' => 4]];
    invokeWsDaveFailClosedMethod($ws, 'handleDaveExecuteTransition', [$data]);

    expect($state->passthroughMode)->toBeTrue()
        ->and($state->protocolVersion)->toBe(0);
});

// ---------------------------------------------------------------------------
// Scenario 3: Active encryption failure — packet DROP, never plaintext
// ---------------------------------------------------------------------------

/**
 * When the DAVE outbound encryptor callback returns an empty string (the
 * VoiceClient-level "drop" signal), the Packet layer stores '' as the audio
 * and libsodium encrypts that empty payload — the original Opus frame is NOT
 * present anywhere in the transmitted wire message.
 */
it('Packet with DAVE encryptor callback returning empty string encrypts empty payload instead of original audio', function (): void {
    if (! sodium_crypto_aead_aes256gcm_is_available()) {
        $this->markTestSkipped('Requires libsodium AES-256-GCM hardware support.');
    }

    $key = str_repeat("\x42", SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    $originalAudio = 'original-opus-frame-data';

    $packet = new Packet(
        $originalAudio,
        0xDEAD,
        1,
        100,
        false,
        $key,
        fn (string $frame): string => '', // DAVE drop: encryptor signals discard
        null,
        42,
    );

    $wire = $packet->getEncryptedMessage();
    $inbound = new Packet($wire, null, null, null, true, $key);

    expect($inbound->getAudioData())->toBe('')
        ->and($inbound->getAudioData())->not->toBe($originalAudio);
});

/**
 * When the DAVE encryptor callback returns null (rather than ''), the Packet
 * class does NOT drop the frame — it silently keeps $decryptedAudio unchanged
 * and encrypts the original audio.  The VoiceClient-level encryptDaveFrame()
 * is responsible for converting a failure into '' before it reaches Packet;
 * Packet itself does not enforce fail-closed semantics.
 */
it('Packet with DAVE encryptor callback returning null preserves original audio (Packet itself does not enforce fail-closed)', function (): void {
    if (! sodium_crypto_aead_aes256gcm_is_available()) {
        $this->markTestSkipped('Requires libsodium AES-256-GCM hardware support.');
    }

    $key = str_repeat("\x42", SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    $originalAudio = 'original-opus-frame-data';

    $packet = new Packet(
        $originalAudio,
        0xDEAD,
        1,
        100,
        false,
        $key,
        fn (string $frame): ?string => null, // Packet ignores null, keeps original audio
        null,
        42,
    );

    $wire = $packet->getEncryptedMessage();
    $inbound = new Packet($wire, null, null, null, true, $key);

    // Packet does not enforce fail-closed — original audio is still present.
    expect($inbound->getAudioData())->toBe($originalAudio);
});

/**
 * End-to-end drop: VoiceClient::encryptDaveFrame() returns '' when DAVE is
 * active (passthroughMode=false) and encryption fails.  The Packet then
 * encrypts '' via libsodium — original audio is never transmitted in plaintext.
 */
it('VoiceClient encryptDaveFrame returning empty string causes Packet to encrypt empty audio not original', function (): void {
    if (! sodium_crypto_aead_aes256gcm_is_available()) {
        $this->markTestSkipped('Requires libsodium AES-256-GCM hardware support.');
    }

    // DAVE encrypt callback returns null → encryptDaveFrame returns '' (passthroughMode=false)
    Runtime::configureCallbacks(
        frameEncryptor: fn (string $frame, int $protocolVersion): ?string => null
    );

    $voiceClient = makeVoiceClientForDaveFailClosedTest(1); // protocol v1 → passthroughMode=false

    $key = str_repeat("\x42", SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
    $originalAudio = 'original-opus-frame-data';

    $packet = new Packet(
        $originalAudio,
        0xDEAD,
        1,
        100,
        false,
        $key,
        [$voiceClient, 'encryptDaveFrame'], // VoiceClient callback returns '' on failure
        null,
        42,
    );

    $wire = $packet->getEncryptedMessage();
    $inbound = new Packet($wire, null, null, null, true, $key);

    // VoiceClient returned '' → Packet encrypted empty audio → original audio is dropped.
    expect($inbound->getAudioData())->toBe('')
        ->and($inbound->getAudioData())->not->toBe($originalAudio);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @param  callable(string): void $sendHook
 * @return array{0: WS, 1: State}
 */
function makeWsForDaveFailClosedTest(TestCase $test, callable $sendHook, bool &$closeCalled = false): array
{
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $state = new State();
    $state->setProtocolVersion(0);
    $state->setIdentity('user1', '123456789');

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, new NullLogger());

    $daveStateProperty = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProperty->setAccessible(true);
    $daveStateProperty->setValue($ws, $state);

    $discordProperty = new \ReflectionProperty(WS::class, 'discord');
    $discordProperty->setAccessible(true);
    $discordProperty->setValue($ws, $discord);

    $socket = invokeWsDaveFailClosedMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send', 'close'])
        ->getMock();
    $socket->method('send')->willReturnCallback($sendHook);
    $socket->method('close')->willReturnCallback(function () use (&$closeCalled): void {
        $closeCalled = true;
    });

    $socketProperty = new \ReflectionProperty(WS::class, 'socket');
    $socketProperty->setAccessible(true);
    $socketProperty->setValue($ws, $socket);

    return [$ws, $state];
}

function makeWsDaveFailClosedWithLogger(TestCase $test, array &$logs): WS
{
    $capturingLogger = new class($logs) extends AbstractLogger {
        public function __construct(private array &$entries)
        {
        }

        public function log($level, string|\Stringable $message, array $context = []): void
        {
            $this->entries[] = json_encode(['level' => $level, 'msg' => (string) $message]);
        }
    };

    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $state = new State();
    $state->setProtocolVersion(0);
    $state->setIdentity('user1', '123456789');

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, $capturingLogger);

    $daveStateProperty = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProperty->setAccessible(true);
    $daveStateProperty->setValue($ws, $state);

    $discordProperty = new \ReflectionProperty(WS::class, 'discord');
    $discordProperty->setAccessible(true);
    $discordProperty->setValue($ws, $discord);

    $socket = invokeWsDaveFailClosedMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send', 'close'])
        ->getMock();
    $socket->method('send')->willReturn(null);
    $socket->method('close')->willReturn(null);

    $socketProperty = new \ReflectionProperty(WS::class, 'socket');
    $socketProperty->setAccessible(true);
    $socketProperty->setValue($ws, $socket);

    return $ws;
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeWsDaveFailClosedMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}

function makeVoiceClientForDaveFailClosedTest(int $protocolVersion): VoiceClient
{
    $voiceClient = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
    $udp = (new \ReflectionClass(UDP::class))->newInstanceWithoutConstructor();

    $state = new State();
    $state->setProtocolVersion($protocolVersion);

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, new NullLogger());

    $daveStateProperty = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProperty->setAccessible(true);
    $daveStateProperty->setValue($ws, $state);

    $ssrcProp = new \ReflectionProperty(VoiceClient::class, 'ssrc');
    $ssrcProp->setAccessible(true);
    $ssrcProp->setValue($voiceClient, null);

    $udp->ws = $ws;
    $voiceClient->udp = $udp;
    $voiceClient->discord = $discord;

    return $voiceClient;
}
