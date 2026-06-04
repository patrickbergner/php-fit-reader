<?php

declare(strict_types=1);

namespace Emontis\FitReader\Value;

use Emontis\FitReader\Protocol\BaseType;

/**
 * Decode the raw bytes of one field (per a FieldDefinition) into a PHP value.
 *
 * Behavior:
 *  - Returns null when ALL elements of an array equal the base-type
 *    invalid sentinel (or for scalars, when the single element is invalid).
 *  - For multi-element fields where the size is greater than one element,
 *    returns an array of values (with invalid elements as null).
 *  - Strings are trimmed at the first NUL.
 *  - The `byte` base type is returned as a binary string.
 */
final class ValueDecoder
{
    public static function decode(string $raw, BaseType $type, bool $littleEndian): mixed
    {
        if ($type === BaseType::String) {
            $nul = strpos($raw, "\x00");
            $str = $nul === false ? $raw : substr($raw, 0, $nul);
            return $str === '' ? null : $str;
        }

        if ($type === BaseType::Byte) {
            // Trim trailing 0xFF sentinel bytes; if all sentinel → null.
            $trim = rtrim($raw, "\xFF");
            return $trim === '' ? null : $trim;
        }

        $elemSize  = $type->size();
        $count     = intdiv(strlen($raw), max(1, $elemSize));
        if ($count === 0) {
            return null;
        }

        $values = [];
        for ($i = 0; $i < $count; $i++) {
            $bytes    = substr($raw, $i * $elemSize, $elemSize);
            $decoded  = self::decodeScalar($bytes, $type, $littleEndian);
            $values[] = self::isInvalid($decoded, $type) ? null : $decoded;
        }

        if ($count === 1) {
            return $values[0];
        }
        // If every element is null, the whole field is invalid → null.
        foreach ($values as $v) {
            if ($v !== null) {
                return $values;
            }
        }
        return null;
    }

    private static function decodeScalar(string $b, BaseType $type, bool $le): int|float|null
    {
        return match ($type) {
            BaseType::Enum, BaseType::Uint8, BaseType::Uint8z => ord($b),
            BaseType::Sint8 => self::signed(ord($b), 8),

            BaseType::Uint16, BaseType::Uint16z => $le
                ? (ord($b[0]) | (ord($b[1]) << 8))
                : ((ord($b[0]) << 8) | ord($b[1])),
            BaseType::Sint16 => self::signed(
                $le ? (ord($b[0]) | (ord($b[1]) << 8))
                    : ((ord($b[0]) << 8) | ord($b[1])),
                16,
            ),

            BaseType::Uint32, BaseType::Uint32z => $le
                ? (ord($b[0]) | (ord($b[1]) << 8) | (ord($b[2]) << 16) | (ord($b[3]) << 24))
                : ((ord($b[0]) << 24) | (ord($b[1]) << 16) | (ord($b[2]) << 8) | ord($b[3])),
            BaseType::Sint32 => self::signed(
                $le ? (ord($b[0]) | (ord($b[1]) << 8) | (ord($b[2]) << 16) | (ord($b[3]) << 24))
                    : ((ord($b[0]) << 24) | (ord($b[1]) << 16) | (ord($b[2]) << 8) | ord($b[3])),
                32,
            ),

            BaseType::Float32 => self::float32($b, $le),
            BaseType::Float64 => self::float64($b, $le),

            BaseType::Uint64, BaseType::Uint64z, BaseType::Sint64 => self::int64($b, $le),

            BaseType::String, BaseType::Byte => null,
        };
    }

    private static function signed(int $v, int $bits): int
    {
        $sign = 1 << ($bits - 1);
        return ($v & $sign) ? $v - (1 << $bits) : $v;
    }

    private static function float32(string $b, bool $le): float
    {
        $arr = unpack($le ? 'g' : 'G', $b);
        return $arr === false ? \NAN : (float) $arr[1];
    }

    private static function float64(string $b, bool $le): float
    {
        $arr = unpack($le ? 'e' : 'E', $b);
        return $arr === false ? \NAN : (float) $arr[1];
    }

    private static function int64(string $b, bool $le): int
    {
        $arr = unpack($le ? 'P' : 'J', $b);
        $v = $arr === false ? 0 : $arr[1];
        // PHP int is always signed on 64-bit, so uint64 and sint64 decode
        // identically here. For unsigned semantics the caller would have to
        // inspect the sign bit; for FIT activity files 64-bit values are rare
        // and we accept the signed view.
        return (int) $v;
    }

    private static function isInvalid(int|float|null $value, BaseType $type): bool
    {
        if ($value === null) {
            return false;
        }
        $sentinel = $type->invalidSentinel();
        if ($sentinel === null) {
            return false;
        }
        if (is_float($sentinel) && is_nan($sentinel)) {
            return is_float($value) && is_nan($value);
        }
        return $value === $sentinel;
    }
}
