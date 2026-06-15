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

namespace Discord\Tests\Unit\Dave;

use Discord\Voice\Dave\Runtime;

it('makeOutputByteBuffer returns zero-initialized values (HIGH-2)', function () {
    if (! Runtime::isAvailable()) {
        $this->markTestSkipped('Requires libdave to be available.');
    }

    $method = new \ReflectionMethod(Runtime::class, 'makeOutputByteBuffer');
    $method->setAccessible(true);

    [$buffer, $length] = $method->invoke(null);

    // The size_t should be initialized to 0
    expect((int) $length[0])->toBe(0);
});
