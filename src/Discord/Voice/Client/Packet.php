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

namespace Discord\Voice\Client;

use Discord\Voice\ByteBuffer\Buffer;
use Discord\Voice\ByteBuffer\FormatPackEnum;
use Discord\Voice\Exceptions\Libraries\LibSodiumNotFoundException;

/**
 * An RTP voice packet: builds outbound frames and decodes inbound frames.
 *
 * Decrypt flow order for inbound packets:
 *  1. AES-256-GCM transport decrypt (libsodium) — `sodium_crypto_aead_aes256gcm_decrypt()`
 *  2. Strip RTP header extension payload — `RtpHeader::stripExtensionPayload()`
 *  3. Optional DAVE inbound callback — `inboundFrameDecryptor` (injected by VoiceClient);
 *     calls `DaveRuntime::decryptWithDecryptor()` when `passthroughMode = false`.
 *
 * Huge thanks to Austin and Michael from JDA for the constants and audio
 * packets. Check out their repo:
 * https://github.com/DV8FromTheWorld/JDA
 *
 * @since 10.19.0
 */
final class Packet
{
    /**
     * The audio header, in binary, containing the version, flags, sequence, timestamp, and SSRC.
     */
    protected string $header;

    /**
     * The buffer containing the voice packet.
     *
     * @deprecated
     */
    protected Buffer $buffer;

    /**
     * The version and flags (first byte of RTP header: V+P+X+CC bits).
     */
    public ?int $versionPlusFlags = null;

    /**
     * The payload type (second byte of RTP header: M+PT bits).
     */
    public ?int $payloadType = null;

    /**
     * The encrypted audio.
     */
    public ?string $encryptedAudio;

    /**
     * The decrypted audio.
     */
    public null|false|string $decryptedAudio;

    /**
     * The secret key.
     */
    public ?string $secretKey;

    /**
     * The raw data.
     */
    protected string $rawData;

    /**
     * Current packet header size. May differ depending on the RTP header.
     */
    protected int $headerSize;

    /**
     * Constructs the voice packet.
     *
     * @param string                                    $data                   The Opus data to encode.
     * @param int                                       $ssrc                   The client SSRC value.
     * @param int                                       $seq                    The packet sequence.
     * @param int                                       $timestamp              The packet timestamp.
     * @param bool                                      $encryption             Whether the packet should be encrypted.
     * @param string|null                               $key                    The encryption key.
     * @param null|callable(string): string             $outboundFrameEncryptor Optional callback to transform outgoing decrypted frame data.
     * @param null|callable(string, self): false|string $inboundFrameDecryptor  Optional callback to transform incoming decrypted frame data.
     * @param int|null                                  $nonce                  32-bit nonce counter for AES-256-GCM. Required for encryption; null is only valid on the decrypt path.
     */
    public function __construct(
        ?string $data = null,
        public ?int $ssrc = null,
        public ?int $seq = null,
        public ?int $timestamp = null,
        bool $decrypt = true,
        protected ?string $key = null,
        protected mixed $outboundFrameEncryptor = null,
        protected mixed $inboundFrameDecryptor = null,
        protected ?int $nonce = null
    ) {
        if (! function_exists('sodium_crypto_secretbox')) {
            throw new LibSodiumNotFoundException('libsodium-php could not be found.');
        }

        if ($decrypt) {
            $this->unpack($data);
            $this->decrypt();
        } else {
            $this->decryptedAudio = $data;
            $this->header = $this->buildHeader()->__toString();
            $this->encrypt();
        }
    }

    /**
     * Unpacks the voice message into an array.
     *
     * C1 (unsigned char)                       | Version + Flags       | 1 bytes | Single byte value of 0x80
     * C1 (unsigned char)                       | Payload Type          | 1 bytes | Single byte value of 0x78
     * n (Unsigned short (big endian))          | Sequence              | 2 bytes
     * I (Unsigned integer (big endian))        | Timestamp             | 4 bytes
     * I (Unsigned integer (big endian))        | SSRC                  | 4 bytes
     * a* (string)                              | Encrypted audio       | n bytes | Binary data of the encrypted audio.
     *
     * @see https://discord.com/developers/docs/topics/voice-connections#transport-encryption-modes-voice-packet-structure
     * @see https://www.php.net/manual/en/function.unpack.php
     * @see https://www.php.net/manual/en/function.pack.php For the formats
     */
    public function unpack(string $message): self
    {
        $byteHeader = $this->setHeader($message);

        if (! $byteHeader) {
            //$this->log->warning('Failed to unpack voice packet Header.', ['message' => $message]);
            return $this;
        }

        $byteData = substr(
            $message,
            HeaderValuesEnum::RTP_HEADER_OR_NONCE_LENGTH->value,
            strlen($message) - HeaderValuesEnum::AUTH_TAG_LENGTH->value - HeaderValuesEnum::RTP_HEADER_OR_NONCE_LENGTH->value
        );

        $unpackedMessage = unpack('Cversion_and_flags/Cpayload_type/nseq/Ntimestamp/Nssrc', $byteHeader);

        if (! $unpackedMessage) {
            //$this->log->warning('Failed to unpack voice packet.', ['message' => $message]);
            return $this;
        }

        $this->rawData = $message;
        $this->header = $byteHeader;
        $this->encryptedAudio = $byteData;

        $this->ssrc = $unpackedMessage['ssrc'];
        $this->seq = $unpackedMessage['seq'];
        $this->timestamp = $unpackedMessage['timestamp'];
        $this->payloadType = $unpackedMessage['payload_type'] ?? null;
        $this->versionPlusFlags = $unpackedMessage['version_and_flags'] ?? null;

        return $this;
    }

    /**
     * Decrypts the voice message.
     */
    public function decrypt(?string $message = null): string|false|null
    {
        if (! $message) {
            $message = $this->rawData ?? null;
        }

        if ($message === '' || $message === null) {
            // throw error here
            return null;
        }

        // total message length
        $len = strlen($message);

        // 2. Extract the header
        $header = $this->getHeader();
        if (! $header) {
            return false;
        }

        // 3. Extract the nonce
        $nonce = substr($message, $len - HeaderValuesEnum::TIMESTAMP_OR_NONCE_INDEX->value, HeaderValuesEnum::TIMESTAMP_OR_NONCE_INDEX->value);
        // 4. Pad the nonce to 12 bytes
        $nonceBuffer = str_pad($nonce, SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES, "\0", STR_PAD_RIGHT);

        // 5. Extract the ciphertext and auth tag
        //    The message: [header][ciphertext][auth tag][nonce]
        //    The size of the ciphertext is: total - headerSize - 16 (auth tag) - 4 (nonce)
        $encryptedLength = $len - $this->headerSize - HeaderValuesEnum::AUTH_TAG_LENGTH->value - HeaderValuesEnum::TIMESTAMP_OR_NONCE_INDEX->value;
        if ($encryptedLength < 0) {
            return false;
        }
        $cipherText = substr($message, $this->headerSize, $encryptedLength);
        $authTag = substr($message, $this->headerSize + $encryptedLength, HeaderValuesEnum::AUTH_TAG_LENGTH->value);

        // Concatenate the ciphertext and the auth tag
        $combined = "$cipherText$authTag";

        $resultMessage = null;

        try {
            // Decrypt the message
            $resultMessage = sodium_crypto_aead_aes256gcm_decrypt(
                $combined,
                $header,
                $nonceBuffer,
                $this->key
            );

            if ($resultMessage !== false && RtpHeader::hasExtension($message)) {
                $baseHeaderSize = RtpHeader::headerSize($message);
                $syntheticPacket = substr($message, 0, $baseHeaderSize + 4).$resultMessage;
                $stripped = RtpHeader::stripExtensionPayload($syntheticPacket, $baseHeaderSize);
                $resultMessage = substr($stripped, $baseHeaderSize);
            }

            // Skip DAVE decryption for the standard Opus silence frame — it is never DAVE-encrypted
            // and passing it to libdave generates noisy "Decrypt skipping silence" C++ log spam.
            if ($resultMessage !== false && $resultMessage !== "\xF8\xFF\xFE" && is_callable($this->inboundFrameDecryptor)) {
                $resultMessage = ($this->inboundFrameDecryptor)($resultMessage, $this);
            }

            // If decryption fails, log the error and return
            // Most of the time, the length is 20 bytes either for a ping, or an empty voice/udp packet
            if ($resultMessage === false) {
                return false;
            }
        } catch (\Throwable $e) {
            //$this->log->error('Exception occurred when decoding voice packet: ' . $e->getMessage());
            //$this->log->error('Trace: ' . $e->getTraceAsString());
            $resultMessage = false;
        } finally {
            $this->decryptedAudio = $resultMessage;
        }

        return $resultMessage;
    }

    public function encrypt()
    {
        $header = $this->getHeader();

        if (is_callable($this->outboundFrameEncryptor)) {
            $transformed = ($this->outboundFrameEncryptor)($this->decryptedAudio);
            if (is_string($transformed)) {
                $this->decryptedAudio = $transformed;
            }
        }

        if ($this->nonce === null) {
            throw new \LogicException('Nonce must be set before encrypting a packet.');
        }

        // pad nonce to 12 bytes for AES 256 GCM
        $nonce = pack('V', $this->nonce);
        $paddedNonce = str_pad($nonce, SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES, "\0", STR_PAD_RIGHT);

        // encrypt the audio
        $this->encryptedAudio = sodium_crypto_aead_aes256gcm_encrypt($this->decryptedAudio, $header, $paddedNonce, $this->key);

        // set the raw encrypted data with header prepended and nonce appended
        $this->rawData = $header.$this->encryptedAudio.$nonce;
    }

    /**
     * Builds the header.
     */
    protected function buildHeader(): Buffer
    {
        $header = new Buffer(HeaderValuesEnum::RTP_HEADER_OR_NONCE_LENGTH->value);
        $header[HeaderValuesEnum::RTP_VERSION_PAD_EXTEND_INDEX->value] = pack(FormatPackEnum::C->value, HeaderValuesEnum::RTP_VERSION_PAD_EXTEND->value);
        $header[HeaderValuesEnum::RTP_PAYLOAD_INDEX->value] = pack(FormatPackEnum::C->value, HeaderValuesEnum::RTP_PAYLOAD_TYPE->value);

        return $header->writeShort($this->seq, HeaderValuesEnum::SEQ_INDEX->value)
            ->writeUInt32BE($this->timestamp, HeaderValuesEnum::TIMESTAMP_OR_NONCE_INDEX->value)
            ->writeUInt32BE($this->ssrc, HeaderValuesEnum::SSRC_INDEX->value);
    }

    /**
     * Sets the header.
     * If no message is provided, it will use the raw data of the packet.
     */
    public function setHeader(?string $message = null): ?string
    {
        if (null === $message) {
            $message = $this->rawData ?? null;
        }

        if (empty($message)) {
            // throw error here
            return null;
        }

        $this->headerSize = RtpHeader::headerSize($message);
        if (RtpHeader::hasExtension($message)) {
            $this->headerSize += 4;
        }

        return substr($message, 0, $this->headerSize);
    }

    /**
     * Returns the header.
     */
    public function getHeader(): ?string
    {
        return $this->header ?? null;
    }

    /**
     * Returns the sequence.
     */
    public function getSequence(): int
    {
        return $this->seq;
    }

    /**
     * Returns the timestamp.
     */
    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    /**
     * Returns the SSRC.
     */
    public function getSSRC(): int
    {
        return $this->ssrc;
    }

    /**
     * Returns the data.
     */
    public function getData(): string
    {
        return $this->buffer->read(
            HeaderValuesEnum::RTP_HEADER_OR_NONCE_LENGTH->value,
            strlen((string) $this->buffer) - HeaderValuesEnum::RTP_HEADER_OR_NONCE_LENGTH->value
        );
    }

    /**
     * Creates a voice packet from data sent from Discord.
     */
    public static function make(string $data): self
    {
        $n = new self('', 0, 0, 0);
        $buff = new Buffer($data);
        $n->setBuffer($buff);
        unset($buff);

        return $n;
    }

    /**
     * Sets the buffer.
     */
    public function setBuffer(Buffer $buffer): self
    {
        $this->buffer = $buffer;

        $this->seq = $this->buffer->readShort(HeaderValuesEnum::SEQ_INDEX->value);
        $this->timestamp = $this->buffer->readUInt(HeaderValuesEnum::TIMESTAMP_OR_NONCE_INDEX->value);
        $this->ssrc = $this->buffer->readUInt(HeaderValuesEnum::SSRC_INDEX->value);

        return $this;
    }

    /**
     * Retrieves the decrypted audio data.
     * Will return null if the audio data is not decrypted and false on error.
     */
    public function getAudioData(): string|false|null
    {
        return $this->decryptedAudio ?? null;
    }

    /**
     * Retrieves the encrypted audio data with header, ready for sending.
     * Will return null if the audio data is not encrypted.
     */
    public function getEncryptedMessage(): string|null
    {
        return $this->rawData ?? null;
    }
}
