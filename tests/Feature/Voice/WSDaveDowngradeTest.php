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
use Discord\Voice\Dave\Runtime;
use Discord\Voice\Dave\SessionHandle;
use Discord\Voice\Dave\State;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Ratchet\Client\WebSocket;

afterEach(function (): void {
    Runtime::reset();
});

// ---------------------------------------------------------------------------
// t2: handleDaveExecuteTransition — v1→v0 downgrade (warning + session reset)
// ---------------------------------------------------------------------------

it('handleDaveExecuteTransition logs warning with previous version when downgrading to protocol version 0', function (): void {
    $logs = [];
    $ws = makeWsForDowngradeTest($this, $logs);

    $state = getDowngradeDaveState($ws);
    $state->setProtocolVersion(1);
    $state->prepareTransition(5, 0); // pendingTransitionId=5, pendingProtocolVersion=0

    $data = (object) ['d' => ['transition_id' => 5]];
    invokeDowngradeWsMethod($ws, 'handleDaveExecuteTransition', [$data]);

    $warningLogs = array_filter($logs, fn (string $e) => str_contains($e, '"level":"warning"'));
    expect($warningLogs)->not->toBeEmpty();

    $allText = implode(' ', $logs);
    expect($allText)->toContain('from v1 to v0');
});

it('handleDaveExecuteTransition sets daveState protocolVersion to 0 after v0 downgrade', function (): void {
    $logs = [];
    $ws = makeWsForDowngradeTest($this, $logs);

    $state = getDowngradeDaveState($ws);
    $state->setProtocolVersion(1);
    $state->prepareTransition(5, 0);

    $data = (object) ['d' => ['transition_id' => 5]];
    invokeDowngradeWsMethod($ws, 'handleDaveExecuteTransition', [$data]);

    expect($state->protocolVersion)->toBe(0)
        ->and($state->pendingTransitionId)->toBeNull();
});

// ---------------------------------------------------------------------------
// t9: resetSession invoked during v0 downgrade
// ---------------------------------------------------------------------------

it('handleDaveExecuteTransition calls resetSession on the existing session when downgrading to v0', function (): void {
    $logs = [];
    $ws = makeWsForDowngradeTest($this, $logs);

    $state = getDowngradeDaveState($ws);
    $state->setProtocolVersion(1);
    $state->prepareTransition(7, 0);

    // Place a non-null session so the resetSession branch is exercised.
    $fakeSession = new SessionHandle(new \stdClass());
    $state->replaceSession($fakeSession);
    expect($state->session)->not->toBeNull();

    $data = (object) ['d' => ['transition_id' => 7]];
    invokeDowngradeWsMethod($ws, 'handleDaveExecuteTransition', [$data]);

    // resetProtocolState() clears the session; its being null here confirms
    // the downgrade branch (and thus DaveRuntime::resetSession()) was reached.
    expect($state->session)->toBeNull()
        ->and($state->protocolVersion)->toBe(0);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @param array<int, string> $logs Passed by reference; captures serialised log entries.
 */
function makeWsForDowngradeTest(TestCase $test, array &$logs): WS
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

    $loggerProperty = new \ReflectionProperty(Discord::class, 'logger');
    $loggerProperty->setAccessible(true);
    $loggerProperty->setValue($discord, $capturingLogger);

    $daveStateProperty = new \ReflectionProperty(WS::class, 'daveState');
    $daveStateProperty->setAccessible(true);
    $daveStateProperty->setValue($ws, $state);

    $discordProperty = new \ReflectionProperty(WS::class, 'discord');
    $discordProperty->setAccessible(true);
    $discordProperty->setValue($ws, $discord);

    $socket = invokeDowngradeWsMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send'])
        ->getMock();
    $socket->method('send')->willReturn(null);

    $socketProperty = new \ReflectionProperty(WS::class, 'socket');
    $socketProperty->setAccessible(true);
    $socketProperty->setValue($ws, $socket);

    return $ws;
}

function getDowngradeDaveState(WS $ws): State
{
    $prop = new \ReflectionProperty(WS::class, 'daveState');
    $prop->setAccessible(true);

    return $prop->getValue($ws);
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeDowngradeWsMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}
