<?php

declare(strict_types=1);

namespace Emontis\FitReader\Protocol;

/**
 * One byte of record header. Two encodings:
 *
 *  Normal:
 *    bit  7   = 0
 *    bit  6   = 1 → definition message, 0 → data message
 *    bit  5   = developer-data flag (definition only)
 *    bit  4   = reserved
 *    bits 3-0 = local message type (0-15)
 *
 *  Compressed-timestamp (data only):
 *    bit  7   = 1
 *    bits 6-5 = local message type (0-3)
 *    bits 4-0 = 5-bit time offset added to the rolling reference timestamp
 */
final readonly class RecordHeader
{
    public function __construct(
        public bool $compressedTimestamp,
        public bool $isDefinition,
        public bool $hasDeveloperData,
        public int $localType,
        public int $timeOffset,
    ) {
    }

    public static function parse(int $byte): self
    {
        if (($byte & 0x80) !== 0) {
            // compressed timestamp header
            return new self(
                compressedTimestamp: true,
                isDefinition: false,
                hasDeveloperData: false,
                localType: ($byte >> 5) & 0x03,
                timeOffset: $byte & 0x1F,
            );
        }
        return new self(
            compressedTimestamp: false,
            isDefinition: ($byte & 0x40) !== 0,
            hasDeveloperData: ($byte & 0x20) !== 0,
            localType: $byte & 0x0F,
            timeOffset: 0,
        );
    }
}
