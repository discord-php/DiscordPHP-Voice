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

use Discord\Voice\Client\WS;

it('redacts secretKey from debug output (HIGH-4)', function () {
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();

    $prop = new \ReflectionProperty(WS::class, 'secretKey');
    $prop->setAccessible(true);
    $prop->setValue($ws, 'super-secret-key-data');

    $debugInfo = $ws->__debugInfo();
    expect($debugInfo['secretKey'])->toBe('[REDACTED]');
});

it('redacts rawKey from debug output (HIGH-4)', function () {
    $ws = (new \ReflectionClass(WS::class))->newInstanceWithoutConstructor();

    $prop = new \ReflectionProperty(WS::class, 'rawKey');
    $prop->setAccessible(true);
    $prop->setValue($ws, [1, 2, 3, 4, 5]);

    $debugInfo = $ws->__debugInfo();
    expect($debugInfo['rawKey'])->toBe('[REDACTED]');
});

it('has secretKey as protected property (HIGH-4)', function () {
    $prop = new \ReflectionProperty(WS::class, 'secretKey');
    expect($prop->isProtected())->toBeTrue();
});

it('has rawKey as protected property (HIGH-4)', function () {
    $prop = new \ReflectionProperty(WS::class, 'rawKey');
    expect($prop->isProtected())->toBeTrue();
});
