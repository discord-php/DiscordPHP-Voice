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

namespace Discord\Voice;

/**
 * Represents Vorbis tags attached to an Opus Ogg file.
 *
 * @link https://www.rfc-editor.org/rfc/rfc7845#section-5.2 Comment Header
 *
 * @since 10.0.0
 *
 * @internal
 */
class OpusTags
{
    /**
     * The vendor of the Opus Ogg.
     *
     * @var string
     */
    public string $vendor;

    /**
     * An array of tags attached to the Opus Ogg.
     *
     * @var string[]
     */
    public array $tags;

    /**
     * Create an instance of OpusTags from a binary string.
     *
     * @param string $data Binary string of data.
     *
     * @throws \UnexpectedValueException If the binary data was missing the magic bytes.
     */
    public function __construct(string $data)
    {
        $magic = substr($data, 0, 8);
        if ($magic !== 'OpusTags') {
            throw new \UnexpectedValueException('Expected OpusTags, found '.bin2hex($magic).'.');
        }

        $dataLen = strlen($data);
        if ($dataLen < 12) {
            throw new \UnexpectedValueException('OpusTags data too short for vendor length field.');
        }

        $vendor_len = unpack('Vvendor_len', $data, 8)['vendor_len'];
        if ($vendor_len > $dataLen - 12) {
            throw new \UnexpectedValueException("OpusTags vendor_len ({$vendor_len}) exceeds available data.");
        }
        $this->vendor = substr($data, 12, $vendor_len);

        $tagsOffset = 12 + $vendor_len;
        if ($dataLen < $tagsOffset + 4) {
            throw new \UnexpectedValueException('OpusTags data too short for tag count field.');
        }

        $tags = [];
        $num_tags = unpack('Vnum_tags', $data, $tagsOffset)['num_tags'];
        if ($num_tags > 1024) {
            throw new \UnexpectedValueException("OpusTags num_tags ({$num_tags}) exceeds maximum of 1024.");
        }

        $pos = $tagsOffset + 4;
        for ($i = 0; $i < $num_tags; $i++) {
            if ($dataLen < $pos + 4) {
                throw new \UnexpectedValueException("OpusTags data truncated at tag {$i} length field.");
            }
            $tag_len = unpack('Vtag_len', $data, $pos)['tag_len'];
            $pos += 4;
            if ($tag_len > $dataLen - $pos) {
                throw new \UnexpectedValueException("OpusTags tag {$i} length ({$tag_len}) exceeds available data.");
            }
            $tags[$i] = substr($data, $pos, $tag_len);
            $pos += $tag_len;
        }
        $this->tags = $tags;
    }
}
