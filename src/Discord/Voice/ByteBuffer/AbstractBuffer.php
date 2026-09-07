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

namespace Discord\Voice\ByteBuffer;

/**
 * @author alexandre433
 */
abstract class AbstractBuffer implements ReadableBuffer, WriteableBuffer
{
    /**
     * @param string|int $argument A binary string to wrap, or an integer size to pre-allocate.
     */
    abstract public function __construct($argument);

    /** @return string The buffer's contents as a raw binary string. */
    abstract public function __toString(): string;

    /** @return int The buffer's length in bytes. */
    abstract public function length(): int;

    /** @return int The offset of the first empty (unwritten) byte. */
    abstract public function getLastEmptyPosition(): int;
}
