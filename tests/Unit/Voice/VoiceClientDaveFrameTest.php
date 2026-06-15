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

namespace Discord\Tests\Unit\Voice;

use Discord\Discord;
use Discord\Voice\Client\Packet;
use Discord\Voice\Client\UDP;
use Discord\Voice\Client\WS;
use Discord\Voice\Dave\Runtime;
use Discord\Voice\Dave\State;
use Discord\Voice\VoiceClient;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

afterEach(function (): void {
    Runtime::reset();
});

it('encrypts and decrypts pass through when the DAVE protocol is disabled', function (): void {
    $voiceClient = makeVoiceClientWithProtocolVersion(0);

    $this->assertSame('audio', $voiceClient->encryptDaveFrame('audio'));
    $this->assertSame('audio', $voiceClient->decryptDaveFrame('audio'));
});

it('uses runtime callbacks when the DAVE protocol is enabled', function (): void {
    Runtime::configureCallbacks(
        fn (string $frame, int $protocolVersion): ?string => "enc:{$protocolVersion}:{$frame}",
        fn (string $frame, int $protocolVersion): string => "dec:{$protocolVersion}:{$frame}"
    );

    $voiceClient = makeVoiceClientWithProtocolVersion(1);

    $this->assertSame('enc:1:audio', $voiceClient->encryptDaveFrame('audio'));
    $this->assertSame('dec:1:audio', $voiceClient->decryptDaveFrame('audio'));
});

it('returns false when the runtime cannot decrypt with the DAVE protocol enabled', function (): void {
    Runtime::configureCallbacks(
        null,
        fn (string $frame, int $protocolVersion): false => false
    );

    $voiceClient = makeVoiceClientWithProtocolVersion(1);

    $this->assertFalse($voiceClient->decryptDaveFrame('audio'));
});

it('decryptDaveFrame uses Runtime callback when no per-user decryptor is registered', function (): void {
    Runtime::configureCallbacks(
        null,
        fn (string $frame, int $protocolVersion): ?string => "dec:{$protocolVersion}:{$frame}"
    );

    $voiceClient = makeVoiceClientWithProtocolVersion(1);

    // Map SSRC 12345 → 'user1', but register no decryptor for 'user1' in daveState.
    $ssrcMapProp = new \ReflectionProperty(VoiceClient::class, 'ssrcToUserId');
    $ssrcMapProp->setAccessible(true);
    $ssrcMapProp->setValue($voiceClient, [12345 => 'user1']);

    $packet = (new \ReflectionClass(Packet::class))->newInstanceWithoutConstructor();
    $ssrcProp = new \ReflectionProperty(Packet::class, 'ssrc');
    $ssrcProp->setAccessible(true);
    $ssrcProp->setValue($packet, 12345);

    expect($voiceClient->decryptDaveFrame('audio', $packet))->toBe('dec:1:audio');
});

it('decryptDaveFrame passes frame through unchanged when DAVE protocol version is zero', function (): void {
    $voiceClient = makeVoiceClientWithProtocolVersion(0);

    expect($voiceClient->decryptDaveFrame('audio'))->toBe('audio');
});

it('decryptDaveFrame passes frame through unchanged while DAVE transition is not executed', function (): void {
    Runtime::configureCallbacks(
        frameDecryptor: fn (string $frame, int $protocolVersion): ?string => null
    );

    $voiceClient = makeVoiceClientWithProtocolVersion(1);
    $daveState = getDaveStateFromClient($voiceClient);
    $daveState->passthroughMode = true;

    expect($voiceClient->decryptDaveFrame('audio'))->toBe('audio')
        ->and($daveState->decryptFailureCount)->toBe(0);
});

// VULN-21 regression: encryptDaveFrame must drop frame (return '') when encryption fails and DAVE is active.

it('encryptDaveFrame drops frame by returning empty string when encryption fails and passthroughMode is false', function (): void {
    Runtime::configureCallbacks(
        frameEncryptor: fn (string $frame, int $protocolVersion): ?string => null
    );

    $voiceClient = makeVoiceClientWithProtocolVersion(1);
    // setProtocolVersion(1) already sets passthroughMode = false on the State.

    expect($voiceClient->encryptDaveFrame('audio'))->toBe('');
});

it('encryptDaveFrame returns raw frame when encryption fails and passthroughMode is true', function (): void {
    Runtime::configureCallbacks(
        frameEncryptor: fn (string $frame, int $protocolVersion): ?string => null
    );

    $voiceClient = makeVoiceClientWithProtocolVersion(1);

    // Override passthroughMode to true to simulate DAVE not yet fully active.
    $ws = $voiceClient->udp->ws;
    $daveStateProp = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProp->setAccessible(true);
    $daveState = $daveStateProp->getValue($ws);
    $daveState->passthroughMode = true;

    expect($voiceClient->encryptDaveFrame('audio'))->toBe('audio');
});

function makeVoiceClientWithProtocolVersion(int $protocolVersion): VoiceClient
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

// ---------------------------------------------------------------------------
// t8: encrypt/decrypt failure counters
// ---------------------------------------------------------------------------

it('encryptDaveFrame increments encryptFailureCount on daveState when encryption fails', function (): void {
    // No frameEncryptor configured and no libdave → encryptMediaFrame returns null → failure.
    $voiceClient = makeVoiceClientWithProtocolVersion(1);
    $daveState = getDaveStateFromClient($voiceClient);

    expect($daveState->encryptFailureCount)->toBe(0);

    $voiceClient->encryptDaveFrame('audio');

    expect($daveState->encryptFailureCount)->toBe(1);
});

it('encryptDaveFrame logs warning after 100 consecutive encrypt failures', function (): void {
    $logs = [];
    $voiceClient = makeVoiceClientForCountTest(1, $logs);
    $daveState = getDaveStateFromClient($voiceClient);

    // Simulate 99 previous failures so the next call hits the modulo-100 threshold.
    $daveState->encryptFailureCount = 99;

    $voiceClient->encryptDaveFrame('audio');

    expect($daveState->encryptFailureCount)->toBe(100);

    $warningLogs = array_filter($logs, fn (string $e) => str_contains($e, '"level":"warning"'));
    expect($warningLogs)->not->toBeEmpty();

    $allText = implode(' ', $logs);
    expect($allText)->toContain('DAVE encrypt failure count: 100');
});

it('decryptDaveFrame increments decryptFailureCount on daveState when decryption returns null', function (): void {
    // frameDecryptor returning null triggers the null branch that increments the counter.
    Runtime::configureCallbacks(
        frameDecryptor: fn (string $frame, int $protocolVersion): ?string => null
    );

    $voiceClient = makeVoiceClientWithProtocolVersion(1);
    $daveState = getDaveStateFromClient($voiceClient);

    expect($daveState->decryptFailureCount)->toBe(0);

    $voiceClient->decryptDaveFrame('audio');

    expect($daveState->decryptFailureCount)->toBe(1);
});

it('decryptDaveFrame logs warning after 100 consecutive decrypt failures', function (): void {
    Runtime::configureCallbacks(
        frameDecryptor: fn (string $frame, int $protocolVersion): ?string => null
    );

    $logs = [];
    $voiceClient = makeVoiceClientForCountTest(1, $logs);
    $daveState = getDaveStateFromClient($voiceClient);

    $daveState->decryptFailureCount = 99;

    $voiceClient->decryptDaveFrame('audio');

    expect($daveState->decryptFailureCount)->toBe(100);

    $warningLogs = array_filter($logs, fn (string $e) => str_contains($e, '"level":"warning"'));
    expect($warningLogs)->not->toBeEmpty();

    $allText = implode(' ', $logs);
    expect($allText)->toContain('DAVE decrypt failure count: 100');
});

it('State::resetProtocolState resets encryptFailureCount and decryptFailureCount to zero', function (): void {
    $state = new State();
    $state->encryptFailureCount = 42;
    $state->decryptFailureCount = 17;

    $state->resetProtocolState();

    expect($state->encryptFailureCount)->toBe(0)
        ->and($state->decryptFailureCount)->toBe(0);
});

// ---------------------------------------------------------------------------
// Fan-out decrypt: SSRC unmapped or userId has no decryptor
// ---------------------------------------------------------------------------

it('decryptDaveFrame fan-out succeeds when SSRC is unmapped but a decryptor is registered', function (): void {
    Runtime::configureCallbacks(
        decryptWithDecryptorCallback: fn (\Discord\Voice\Dave\DecryptorHandle $d, string $frame): string => "fanned:{$frame}",
    );

    $voiceClient = makeVoiceClientWithProtocolVersion(1);
    $daveState = getDaveStateFromClient($voiceClient);

    // Register a decryptor for 'user1' but leave ssrcToUserId empty.
    $daveState->setDecryptor('user1', new \Discord\Voice\Dave\DecryptorHandle('fake'));

    // Build a packet whose SSRC has no ssrcToUserId entry.
    $packet = makePacketWithSSRCForDaveTest(99999);

    expect($voiceClient->decryptDaveFrame('audio', $packet))->toBe('fanned:audio');
});

it('decryptDaveFrame fan-out succeeds when userId is known but its decryptor is absent', function (): void {
    Runtime::configureCallbacks(
        decryptWithDecryptorCallback: fn (\Discord\Voice\Dave\DecryptorHandle $d, string $frame): string => "fanned:{$frame}",
    );

    $voiceClient = makeVoiceClientWithProtocolVersion(1);
    $daveState = getDaveStateFromClient($voiceClient);

    // 'user2' has a decryptor; 'user1' (the mapped user) does not.
    $daveState->setDecryptor('user2', new \Discord\Voice\Dave\DecryptorHandle('fake'));

    $ssrcMapProp = new \ReflectionProperty(VoiceClient::class, 'ssrcToUserId');
    $ssrcMapProp->setAccessible(true);
    $ssrcMapProp->setValue($voiceClient, [12345 => 'user1']);

    $packet = makePacketWithSSRCForDaveTest(12345);

    expect($voiceClient->decryptDaveFrame('audio', $packet))->toBe('fanned:audio');
});

it('decryptDaveFrame falls back to frameDecryptor when all fan-out decryptors fail', function (): void {
    Runtime::configureCallbacks(
        decryptWithDecryptorCallback: fn (\Discord\Voice\Dave\DecryptorHandle $d, string $frame): ?string => null,
        frameDecryptor: fn (string $frame, int $v): string => "fallback:{$frame}",
    );

    $voiceClient = makeVoiceClientWithProtocolVersion(1);
    $daveState = getDaveStateFromClient($voiceClient);

    $daveState->setDecryptor('user1', new \Discord\Voice\Dave\DecryptorHandle('fake'));

    $packet = makePacketWithSSRCForDaveTest(99999);

    expect($voiceClient->decryptDaveFrame('audio', $packet))->toBe('fallback:audio');
});

it('decryptDaveFrame stops fan-out on explicit false and does not try frameDecryptor', function (): void {
    $decryptorCalls = 0;
    $frameCalls = 0;

    Runtime::configureCallbacks(
        decryptWithDecryptorCallback: function (\Discord\Voice\Dave\DecryptorHandle $d, string $frame) use (&$decryptorCalls): false {
            $decryptorCalls++;

            return false;
        },
        frameDecryptor: function (string $frame, int $v) use (&$frameCalls): string {
            $frameCalls++;

            return "fallback:{$frame}";
        },
    );

    $voiceClient = makeVoiceClientWithProtocolVersion(1);
    $daveState = getDaveStateFromClient($voiceClient);

    $daveState->setDecryptor('user1', new \Discord\Voice\Dave\DecryptorHandle('fake'));
    $daveState->setDecryptor('user2', new \Discord\Voice\Dave\DecryptorHandle('fake2'));

    $packet = makePacketWithSSRCForDaveTest(99999);

    $result = $voiceClient->decryptDaveFrame('audio', $packet);

    expect($result)->toBeFalse()
        ->and($decryptorCalls)->toBe(1)  // stops after first false
        ->and($frameCalls)->toBe(0);     // frameDecryptor never reached
});

function getDaveStateFromClient(VoiceClient $voiceClient): State
{
    $daveStateProp = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProp->setAccessible(true);

    return $daveStateProp->getValue($voiceClient->udp->ws);
}

/**
 * @param array<int, string> $logs Passed by reference; captures serialised log entries.
 */
function makeVoiceClientForCountTest(int $protocolVersion, array &$logs): VoiceClient
{
    $capturingLogger = new class($logs) extends AbstractLogger {
        public function __construct(private array &$entries)
        {
        }

        public function log($level, string|\Stringable $message, array $context = []): void
        {
            $this->entries[] = json_encode(['level' => $level, 'msg' => (string) $message, 'ctx' => $context]);
        }
    };

    $voiceClient = (new \ReflectionClass(VoiceClient::class))->newInstanceWithoutConstructor();
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
    $udp = (new \ReflectionClass(UDP::class))->newInstanceWithoutConstructor();

    $state = new State();
    $state->setProtocolVersion($protocolVersion);

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, $capturingLogger);

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

function makePacketWithSSRCForDaveTest(int $ssrc): Packet
{
    $packet = (new \ReflectionClass(Packet::class))->newInstanceWithoutConstructor();
    $ssrcProp = new \ReflectionProperty(Packet::class, 'ssrc');
    $ssrcProp->setAccessible(true);
    $ssrcProp->setValue($packet, $ssrc);

    return $packet;
}
