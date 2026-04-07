<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP project.
 *
 * Copyright (c) 2015-2022 David Cole <david.cole1340@gmail.com>
 * Copyright (c) 2020-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Discord\Voice\Helpers\ByteBuffer;

/**
 * @author alexandre433
 */
abstract class AbstractBuffer implements ReadableBuffer, WriteableBuffer
{
    abstract public function __construct($argument);

    abstract public function __toString(): string;

    abstract public function length(): int;

    abstract public function getLastEmptyPosition(): int;
}
