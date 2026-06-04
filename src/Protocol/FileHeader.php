<?php

declare(strict_types=1);

namespace Emontis\FitReader\Protocol;

use Emontis\FitReader\Exception\CrcMismatchException;
use Emontis\FitReader\Exception\InvalidFileException;
use Emontis\FitReader\Io\BinaryStream;
use Emontis\FitReader\Io\Crc16;

final readonly class FileHeader
{
    public function __construct(
        public int $headerSize,
        public int $protocolVersion,
        public int $profileVersion,
        public int $dataSize,
        public ?int $headerCrc,
    ) {
    }

    /**
     * Read a FIT file header from the stream. Verifies the magic and the
     * optional 14-byte header CRC. The header bytes are read via the stream's
     * CRC-tracking read(), so the 12/14 header bytes contribute to the running
     * file CRC — as the FIT spec requires (the header is part of the file CRC
     * scope). The optional 14-byte header CRC is verified separately, against a
     * fresh Crc16 over the first 12 bytes.
     */
    public static function readFrom(BinaryStream $stream, bool $verifyCrc = true): self
    {
        // We need to read the first byte to learn the header size, then the
        // rest. We want the entire header to contribute to the file CRC.

        $first  = $stream->read(1);
        $size   = ord($first);
        if ($size !== 12 && $size !== 14) {
            throw new InvalidFileException("Invalid FIT header size {$size}");
        }
        $rest = $stream->read($size - 1);
        $raw  = $first . $rest;

        $protocol      = ord($raw[1]);
        $profile       = ord($raw[2]) | (ord($raw[3]) << 8);
        $dataSize      = ord($raw[4]) | (ord($raw[5]) << 8) | (ord($raw[6]) << 16) | (ord($raw[7]) << 24);
        $magic         = substr($raw, 8, 4);

        if ($magic !== '.FIT') {
            throw new InvalidFileException("Bad FIT magic: " . bin2hex($magic));
        }

        $headerCrc = null;
        if ($size === 14) {
            $headerCrc = ord($raw[12]) | (ord($raw[13]) << 8);
            if ($verifyCrc && $headerCrc !== 0x0000) {
                $expected = Crc16::of(substr($raw, 0, 12));
                if ($expected !== $headerCrc) {
                    throw new CrcMismatchException('header', $expected, $headerCrc);
                }
            }
        }

        return new self($size, $protocol, $profile, $dataSize, $headerCrc);
    }
}
