<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP project.
 *
 * Copyright (c) 2015-present David Cole <david.cole1340@gmail.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Discord\Voice\Client;

/**
 * Enum for header values used in Discord voice client.
 *
 * @since 10.19.0
 */
enum HeaderValuesEnum: int
{
    case RTP_HEADER_OR_NONCE_LENGTH = 12;

    case RTP_VERSION_PAD_EXTEND_INDEX = 0;

    case RTP_VERSION_PAD_EXTEND = 0x80;

    case RTP_PAYLOAD_INDEX = 1;

    case RTP_PAYLOAD_TYPE = 0x78;

    case SEQ_INDEX = 2;

    case TIMESTAMP_OR_NONCE_INDEX = 4;

    case SSRC_INDEX = 8;

    case AUTH_TAG_LENGTH = 16;
}
