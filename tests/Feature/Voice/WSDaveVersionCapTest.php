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
use Discord\Voice\Dave\State;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Ratchet\Client\WebSocket;

afterEach(function (): void {
    Runtime::reset();
});

// ---------------------------------------------------------------------------
// resolveDaveProtocolVersion — version cap and clamping behaviour
// ---------------------------------------------------------------------------

it('resolveDaveProtocolVersion returns MAX when offered equals MAX_DAVE_PROTOCOL_VERSION', function (): void {
    $logs = [];
    $ws = makeWsForVersionCapTest($this, $logs);

    $result = invokeVersionCapMethod($ws, 'resolveDaveProtocolVersion', [WS::MAX_DAVE_PROTOCOL_VERSION]);

    expect($result)->toBe(WS::MAX_DAVE_PROTOCOL_VERSION);

    $warningLogs = array_filter($logs, fn (string $e) => str_contains($e, '"level":"warning"'));
    expect($warningLogs)->toBeEmpty();
});

it('resolveDaveProtocolVersion returns offered version without warning when offered < maxDaveProtocolVersion', function (): void {
    $logs = [];
    $ws = makeWsForVersionCapTest($this, $logs);

    // Raise maxDaveProtocolVersion above MAX_DAVE_PROTOCOL_VERSION so we can offer a lower positive value.
    $maxProp = new \ReflectionProperty(WS::class, 'maxDaveProtocolVersion');
    $maxProp->setAccessible(true);
    $maxProp->setValue($ws, 2);

    $result = invokeVersionCapMethod($ws, 'resolveDaveProtocolVersion', [1]);

    expect($result)->toBe(1);

    $warningLogs = array_filter($logs, fn (string $e) => str_contains($e, '"level":"warning"'));
    expect($warningLogs)->toBeEmpty();
});

it('resolveDaveProtocolVersion logs warning and clamps when offered version exceeds maxDaveProtocolVersion', function (): void {
    $logs = [];
    $ws = makeWsForVersionCapTest($this, $logs);

    // maxDaveProtocolVersion defaults to MAX_DAVE_PROTOCOL_VERSION (1); offer 2 to trigger clamp.
    $result = invokeVersionCapMethod($ws, 'resolveDaveProtocolVersion', [2]);

    expect($result)->toBe(WS::MAX_DAVE_PROTOCOL_VERSION);

    $warningLogs = array_filter($logs, fn (string $e) => str_contains($e, '"level":"warning"'));
    expect($warningLogs)->not->toBeEmpty();

    $allText = implode(' ', $logs);
    expect($allText)->toContain('clamping');
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @param array<int, string> $logs Passed by reference; captures serialised log entries.
 */
function makeWsForVersionCapTest(TestCase $test, array &$logs): WS
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

    $socket = invokeVersionCapMethod($test, 'getMockBuilder', [WebSocket::class])
        ->disableOriginalConstructor()
        ->onlyMethods(['send'])
        ->getMock();
    $socket->method('send')->willReturn(null);

    $socketProperty = new \ReflectionProperty(WS::class, 'socket');
    $socketProperty->setAccessible(true);
    $socketProperty->setValue($ws, $socket);

    return $ws;
}

/**
 * @param array<int, mixed> $arguments
 */
function invokeVersionCapMethod(object $object, string $method, array $arguments = []): mixed
{
    $reflectionMethod = new \ReflectionMethod($object, $method);
    $reflectionMethod->setAccessible(true);

    return $reflectionMethod->invokeArgs($object, $arguments);
}
