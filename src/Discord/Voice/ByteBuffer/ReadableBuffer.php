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
interface ReadableBuffer
{
    /**
     * Reads `$length` raw bytes starting at `$offset`.
     *
     * @param int $offset Byte offset to read from.
     * @param int $length Number of bytes to read.
     *
     * @return string The bytes read.
     */
    public function read(int $offset, int $length);

    /**
     * Reads an unsigned 8-bit integer at `$offset`.
     *
     * @param int $offset
     *
     * @return int
     */
    public function readInt8(int $offset);

    /**
     * Reads an unsigned 16-bit big-endian integer at `$offset`.
     *
     * @param int $offset
     *
     * @return int
     */
    public function readInt16BE(int $offset);

    /**
     * Reads an unsigned 16-bit little-endian integer at `$offset`.
     *
     * @param int $offset
     *
     * @return int
     */
    public function readInt16LE(int $offset);

    /**
     * Reads an unsigned 32-bit big-endian integer at `$offset`.
     *
     * @param int $offset
     *
     * @return int
     */
    public function readInt32BE(int $offset);

    /**
     * Reads an unsigned 32-bit little-endian integer at `$offset`.
     *
     * @param int $offset
     *
     * @return int
     */
    public function readInt32LE(int $offset);
}
