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

use Discord\Voice\Helpers\Buffer;
use Generator;
use React\Promise\PromiseInterface;

/**
 * Represents a page in an Ogg container.
 *
 * @link https://www.rfc-editor.org/rfc/rfc3533
 *
 * @since 10.0.0
 *
 * @internal
 */
class OggPage
{
    /**
     * Binary format string used to parse header.
     *
     * @var string
     */
    private const FORMAT = 'Cversion/Cheader_type/Pgranule_position/Vbitstream_sn/Vpage_seq/Vcsum/Cpage_segments';

    /**
     * Create a new Ogg page.
     *
     * @param int    $version         The version number of the Ogg file format used in this stream.
     * @param int    $headerType      Identifies the specific type of this page.
     * @param int    $granulePosition Contains position information.
     * @param int    $bitstreamSn     Contains the unique serial number by which the logical bitstream is identified.
     * @param int    $pageSeq         Contains the sequence number of the page so the decoder can identify page loss.
     * @param int    $checksum        Contains a 32 bit CRC checksum of the page (including header with zero CRC field and page content).
     * @param int[]  $pageSegments    A list of page segment lengths which were contained within the page.
     * @param string $segmentData     The data of all the page segments concatenated.
     *
     * @link https://www.rfc-editor.org/rfc/rfc3533#section-6 The Ogg page format
     */
    private function __construct(
        private int $version,
        private int $headerType,
        private int $granulePosition,
        private int $bitstreamSn,
        private int $pageSeq,
        private int $checksum,
        private array $pageSegments,
        public string $segmentData,
    ) {
    }

    /**
     * Read an Ogg page from a buffer.
     *
     * @param Buffer $buffer  Buffer to read the Ogg page from.
     * @param ?int   $timeout Time in milliseconds before a buffer read times out.
     *
     * @return PromiseInterface<OggPage> Promise containing the Ogg page.
     *
     * @throws \UnexpectedValueException If the buffer is out of sync and an invalid header is read.
     */
    public static function fromBuffer(Buffer $buffer, ?int $timeout = -1): PromiseInterface
    {
        $header = null;
        $pageSegments = [];
        $rawHeaderData = '';
        $rawSegmentTable = '';

        return $buffer->read(4, timeout: $timeout)->then(function ($magic) use ($buffer, $timeout) {
            if ($magic !== 'OggS') {
                throw new \UnexpectedValueException("Invalid Ogg page header, expected OggS got {$magic}.");
            }

            return $buffer->read(23, timeout: $timeout);
        })
            // Reading header
            ->then(function ($data) use ($buffer, &$header, &$rawHeaderData, $timeout) {
                $rawHeaderData = $data;
                $header = unpack(self::FORMAT, $data);

                if ($header['page_segments'] === 0) {
                    // RFC 3533 §6: a valid Ogg page must contain at least one lace value.
                    throw new \UnexpectedValueException('Invalid Ogg page: page_segments must not be zero (RFC 3533 §6).');
                }

                return $buffer->read($header['page_segments'], timeout: $timeout);
            })
            // Reading page segment lengths
            ->then(function ($data) use ($buffer, &$pageSegments, &$rawSegmentTable, $timeout) {
                $rawSegmentTable = $data;
                $pageSegments = unpack('C*', $data);
                $data = array_sum($pageSegments);

                if ($data > 65025) {
                    // Ogg spec maximum: 255 segments × 255 bytes each = 65025 bytes.
                    throw new \UnexpectedValueException("Invalid Ogg page: body size {$data} exceeds maximum 65025 bytes (255 segments × 255 bytes).");
                }

                return $buffer->read($data, timeout: $timeout);
            })
            // Reading segment data
            ->then(function ($data) use (&$header, &$pageSegments, &$rawHeaderData, &$rawSegmentTable) {
                // Validate Ogg CRC-32 (poly 0x04C11DB7, init 0, no final XOR).
                // The checksum is computed over the full page with its own 4-byte field zeroed.
                // The checksum field occupies bytes 22–25 of the page (after 'OggS' + 23-byte header).
                $fullPage = 'OggS'.$rawHeaderData.$rawSegmentTable.$data;
                $zeroed = substr($fullPage, 0, 22)."\x00\x00\x00\x00".substr($fullPage, 26);
                if (self::oggCrc32($zeroed) !== $header['csum']) {
                    throw new \UnexpectedValueException('Invalid Ogg page checksum: CRC mismatch.');
                }

                return new OggPage(
                    $header['version'],
                    $header['header_type'],
                    $header['granule_position'],
                    $header['bitstream_sn'],
                    $header['page_seq'],
                    $header['csum'],
                    $pageSegments,
                    $data
                );
            });
    }

    /**
     * Precomputed 256-entry CRC lookup table (Ogg polynomial 0x04C11DB7).
     * Populated once on first use via {@see buildCrcTable()}.
     *
     * @var int[]|null
     */
    private static ?array $crcTable = null;

    /**
     * Build the 256-entry CRC lookup table for polynomial 0x04C11DB7.
     *
     * @return int[]
     */
    private static function buildCrcTable(): array
    {
        $table = [];
        for ($i = 0; $i < 256; $i++) {
            $crc = $i << 24;
            for ($j = 0; $j < 8; $j++) {
                $crc = ($crc & 0x80000000)
                    ? (($crc << 1) ^ 0x04C11DB7) & 0xFFFFFFFF
                    : ($crc << 1) & 0xFFFFFFFF;
            }
            $table[$i] = $crc;
        }

        return $table;
    }

    /**
     * Compute the Ogg-specific CRC-32 checksum using a precomputed lookup table.
     *
     * Uses polynomial 0x04C11DB7 with an initial value of 0 and no final XOR,
     * which differs from the standard CRC-32 used by PHP's crc32().
     *
     * The lookup table reduces the inner 8-iteration bit loop to a single
     * array access per byte, significantly reducing CPU time on the event loop.
     *
     * @param string $data Raw page bytes with the 4-byte checksum field zeroed out.
     *
     * @return int The computed CRC-32 value.
     */
    private static function oggCrc32(string $data): int
    {
        if (self::$crcTable === null) {
            self::$crcTable = self::buildCrcTable();
        }

        $crc = 0;
        $len = strlen($data);
        $table = self::$crcTable;

        for ($i = 0; $i < $len; $i++) {
            $crc = ($table[(($crc >> 24) ^ ord($data[$i])) & 0xFF] ^ ($crc << 8)) & 0xFFFFFFFF;
        }

        return $crc;
    }

    /**
     * Iterates through the packets contained within the stream, yielding an
     * array containing binary data and whether the packet is complete, from a
     * generator.
     *
     * @return Generator
     */
    public function iterPackets()
    {
        $packetLen = 0;
        $offset = 0;
        $partial = true;

        foreach ($this->pageSegments as $seg) {
            $packetLen += $seg;
            if ($seg === 255) {
                $partial = true;
                continue;
            }

            yield [substr($this->segmentData, $offset, $packetLen), true];
            $offset += $packetLen;
            $packetLen = 0;
            $partial = false;
        }

        if ($partial) {
            yield [substr($this->segmentData, $offset), false];
        }
    }
}
