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
use Discord\Voice\Client\WS;
use Discord\Voice\Dave\BinaryFrame;
use Discord\Voice\Dave\Runtime;
use Discord\Voice\Dave\SessionHandle;
use Discord\Voice\Dave\State;
use Discord\WebSockets\Op;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Ratchet\Client\WebSocket;

afterEach(function (): void {
    Runtime::reset();
});

// ---------------------------------------------------------------------------
// t5: setExternalSender failure logs error
// ---------------------------------------------------------------------------

it('handleDaveMlsExternalSender logs error when setExternalSender returns false', function (): void {
    // availabilityOverride: true but no real FFI loaded → ffi() returns null → setExternalSender returns false
    Runtime::configureCallbacks(availabilityOverride: true);

    $logs = [];
    $ws = makeWsForFailureLoggingTest($this, $logs);

    $state = getFailureDaveState($ws);
    $fakeSession = new SessionHandle(new \stdClass());
    $state->replaceSession($fakeSession);

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_EXTERNAL_SENDER, 'external-sender-bytes');
    invokeFailureWsMethod($ws, 'handleDaveMlsExternalSender', [$frame]);

    $errorLogs = array_filter($logs, fn (string $entry) => str_contains($entry, 'error'));
    expect($errorLogs)->not->toBeEmpty();

    $allLogText = implode(' ', $logs);
    expect($allLogText)->toContain('Failed to set DAVE MLS external sender');
});

it('handleDaveMlsExternalSender still stores externalSenderPackage even when setExternalSender fails', function (): void {
    Runtime::configureCallbacks(availabilityOverride: true);

    $logs = [];
    $ws = makeWsForFailureLoggingTest($this, $logs);

    $state = getFailureDaveState($ws);
    $fakeSession = new SessionHandle(new \stdClass());
    $state->replaceSession($fakeSession);

    $frame = new BinaryFrame(1, Op::VOICE_DAVE_MLS_EXTERNAL_SENDER, 'external-sender-bytes');
    invokeFailureWsMethod($ws, 'handleDaveMlsExternalSender', [$frame]);

    expect($state->externalSenderPackage)->toBe('external-sender-bytes');
});

// ---------------------------------------------------------------------------
// t6: setSessionProtocolVersion failure logs error
// ---------------------------------------------------------------------------

it('handleDaveExecuteTransition logs error when setSessionProtocolVersion returns false', function (): void {
    // availabilityOverride: true but no real FFI loaded → ffi() returns null → setSessionProtocolVersion returns false
    Runtime::configureCallbacks(availabilityOverride: true);

    $logs = [];
    $ws = makeWsForFailureLoggingTest($this, $logs);

    $state = getFailureDaveState($ws);
    $fakeSession = new SessionHandle(new \stdClass());
    $state->replaceSession($fakeSession);
    $state->prepareTransition(42, 1);

    $data = (object) ['d' => ['transition_id' => 42]];
    invokeFailureWsMethod($ws, 'handleDaveExecuteTransition', [$data]);

    $errorLogs = array_filter($logs, fn (string $entry) => str_contains($entry, 'error'));
    expect($errorLogs)->not->toBeEmpty();

    $allLogText = implode(' ', $logs);
    expect($allLogText)->toContain('Failed to set DAVE session protocol version');
});

it('handleDaveExecuteTransition still executes the transition even when setSessionProtocolVersion fails', function (): void {
    Runtime::configureCallbacks(availabilityOverride: true);

    $logs = [];
    $ws = makeWsForFailureLoggingTest($this, $logs);

    $state = getFailureDaveState($ws);
    $fakeSession = new SessionHandle(new \stdClass());
    $state->replaceSession($fakeSession);
    $state->prepareTransition(42, 1);

    expect($state->pendingTransitionId)->toBe(42);

    $data = (object) ['d' => ['transition_id' => 42]];
    invokeFailureWsMethod($ws, 'handleDaveExecuteTransition', [$data]);

    // Transition must be cleared even though setSessionProtocolVersion failed
    expect($state->pendingTransitionId)->toBeNull();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @param array<int, string> $logs Passed by reference; captures serialised log entries.
 */
function makeWsForFailureLoggingTest(TestCase $test, array &$logs): WS
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

    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();
    $discord = (new \ReflectionClass(Discord::class))->newInstanceWithoutConstructor();
    $state = new State();
    $state->setProtocolVersion(0);

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, $capturingLogger);

    $daveStateProperty = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProperty->setAccessible(true);
    $daveStateProperty->setValue($ws, $state);

    $discordProperty = new \ReflectionProperty(WS::class, 'discord');
    $discordProperty->setAccessible(true);
    $discordProperty->setValue($ws, $discord);

    $socket = invokeFailureWsMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send'])
        ->getMock();
    $socket->method('send')->willReturn(null);

    $socketProperty = new \ReflectionProperty(WS::class, 'socket');
    $socketProperty->setAccessible(true);
    $socketProperty->setValue($ws, $socket);

    return $ws;
}

function getFailureDaveState(WS $ws): State
{
    $prop = new \ReflectionProperty(WS::class, 'daveState');
    $prop->setAccessible(true);

    return $prop->getValue($ws);
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeFailureWsMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}
