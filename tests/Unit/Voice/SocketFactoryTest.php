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

use Discord\Factory\SocketFactory;
use React\Dns\Resolver\ResolverInterface;
use React\EventLoop\Loop;

it('throws when neither resolver nor WS is provided', function (): void {
    expect(fn (): SocketFactory => new SocketFactory())
        ->toThrow(\InvalidArgumentException::class, 'A DNS resolver or WS instance must be provided to SocketFactory.');
});

it('accepts an explicit resolver without requiring a WS instance', function (): void {
    $resolver = $this->getMockBuilder(ResolverInterface::class)->getMock();

    $factory = new SocketFactory(Loop::get(), $resolver);

    expect($factory)->toBeInstanceOf(SocketFactory::class);
});
