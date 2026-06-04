<?php

declare(strict_types=1);

namespace Emontis\FitReader\Protocol;

/**
 * FIT base types. The hex code's bit 7 indicates an endianness-sensitive
 * (multi-byte) type; bits 4-0 identify the type within its size class.
 *
 * The library normalizes invalid sentinels to null on decode, so callers
 * never see e.g. uint16 0xFFFF as a real value.
 */
enum BaseType: int
{
    case Enum    = 0x00;
    case Sint8   = 0x01;
    case Uint8   = 0x02;
    case Sint16  = 0x83;
    case Uint16  = 0x84;
    case Sint32  = 0x85;
    case Uint32  = 0x86;
    case String  = 0x07;
    case Float32 = 0x88;
    case Float64 = 0x89;
    case Uint8z  = 0x0A;
    case Uint16z = 0x8B;
    case Uint32z = 0x8C;
    case Byte    = 0x0D;
    case Sint64  = 0x8E;
    case Uint64  = 0x8F;
    case Uint64z = 0x90;

    public static function tryFromByte(int $byte): ?self
    {
        return self::tryFrom($byte) ?? self::tryFrom($byte & 0x9F);
    }

    public function size(): int
    {
        return match ($this) {
            self::Enum, self::Sint8, self::Uint8, self::Uint8z, self::Byte, self::String => 1,
            self::Sint16, self::Uint16, self::Uint16z                                    => 2,
            self::Sint32, self::Uint32, self::Uint32z, self::Float32                     => 4,
            self::Float64, self::Sint64, self::Uint64, self::Uint64z                     => 8,
        };
    }

    /**
     * Raw invalid sentinel for this type. For string/byte we return null
     * because the sentinel concept doesn't apply per-byte.
     */
    public function invalidSentinel(): int|float|null
    {
        return match ($this) {
            self::Enum, self::Uint8, self::Byte  => 0xFF,
            self::Sint8                          => 0x7F,
            self::Uint8z                         => 0x00,
            self::Sint16                         => 0x7FFF,
            self::Uint16                         => 0xFFFF,
            self::Uint16z                        => 0x0000,
            self::Sint32                         => 0x7FFFFFFF,
            self::Uint32                         => 0xFFFFFFFF,
            self::Uint32z                        => 0x00000000,
            self::Float32, self::Float64         => \NAN,
            self::Sint64                         => 0x7FFFFFFFFFFFFFFF,
            self::Uint64                         => -1, // 0xFFFFFFFFFFFFFFFF as signed PHP int
            self::Uint64z                        => 0x0000000000000000,
            self::String                         => null,
        };
    }
}
