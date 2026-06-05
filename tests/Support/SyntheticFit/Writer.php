<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Support\SyntheticFit;

use Emontis\FitReader\Io\Crc16;
use Emontis\FitReader\Profile\FieldDef;
use Emontis\FitReader\Profile\MessageDef;
use Emontis\FitReader\Profile\Profile;
use Emontis\FitReader\Protocol\BaseType;
use Emontis\FitReader\Value\FitTimestamp;

/**
 * Minimal FIT binary writer — exists only to seed test fixtures under
 * samples/default/. NOT a public API; the production library is read-only.
 *
 * Inverts {@see \Emontis\FitReader\Protocol\Decoder} for a curated subset:
 * single-byte record headers, little-endian, no developer fields, no
 * compressed timestamps, no message arrays, fixed-width strings sized at
 * definition time. Sufficient for the message kinds listed in
 * {@see self::GLOBAL_NUMS}.
 *
 * Usage:
 *
 *     (new Writer())
 *         ->add('file_id', ['type' => 'activity', 'manufacturer' => 'garmin',
 *                           'product' => 1234, 'serial_number' => 99,
 *                           'time_created' => new DateTimeImmutable('...')])
 *         ->add('record',  ['timestamp' => $ts, 'heart_rate' => 145, ...])
 *         ->add(...)
 *         ->writeTo($path);
 */
final class Writer
{
    /** Message-name → global message number lookup for the kinds we support. */
    private const GLOBAL_NUMS = [
        'file_id'         => 0,
        'capabilities'    => 1,
        'device_settings' => 2,
        'user_profile'    => 3,
        'sport'           => 12,
        'session'         => 18,
        'lap'             => 19,
        'record'          => 20,
        'event'           => 21,
        'device_info'     => 23,
        'activity'        => 34,
        'file_creator'    => 49,
        'field_description' => 206,
    ];

    /** @var array<string, int>  cache key → local type id (0..15) */
    private array $defCache = [];

    private int $nextLocalType = 0;

    private string $body = '';

    /**
     * Append one message. `$fields` is keyed by profile field name; values
     * can be:
     *   - DateTimeInterface for date_time fields
     *   - float degrees for semicircles fields
     *   - int or string label for enum fields
     *   - any numeric for scaled fields (engineering units)
     *   - string for String fields
     *   - null to encode the base-type sentinel
     *
     * @param array<string, mixed> $fields
     */
    public function add(string $messageName, array $fields): self
    {
        $msgDef = self::messageDef($messageName);

        // Resolve field defs and per-field byte sizes, in fieldDefNum order.
        $present = [];
        foreach ($fields as $name => $value) {
            $fdef = self::fieldDefByName($msgDef, $name);
            if ($fdef === null) {
                throw new \InvalidArgumentException(
                    "Unknown field '{$name}' on message '{$messageName}'"
                );
            }
            $size = self::fieldSize($fdef, $value);
            $present[$fdef->fieldDefNum] = [
                'def'   => $fdef,
                'size'  => $size,
                'value' => $value,
            ];
        }
        ksort($present);

        $cacheKey = $messageName . '|';
        foreach ($present as $fid => $info) {
            $cacheKey .= $fid . ':' . $info['size'] . ',';
        }

        if (!isset($this->defCache[$cacheKey])) {
            $localType = $this->nextLocalType;
            $this->nextLocalType = ($this->nextLocalType + 1) & 0x0F;
            $this->defCache[$cacheKey] = $localType;
            $this->body .= self::definitionBytes($localType, $msgDef, $present);
        }
        $localType = $this->defCache[$cacheKey];

        $this->body .= self::byte($localType);
        foreach ($present as $info) {
            $this->body .= self::encodeValue($info['def'], $info['size'], $info['value']);
        }
        return $this;
    }

    /**
     * Append a message that also carries developer (Connect IQ) fields. Emits a
     * definition with the developer-data flag (0x20) and a developer
     * field-definition section, then the data. Each $devFields entry is the raw
     * (unscaled) value the decoder will scale via its `field_description`.
     *
     * @param array<string, mixed> $fields
     * @param list<array{fieldNum: int, devDataIndex: int, baseType: BaseType, value: int|float}> $devFields
     */
    public function addWithDeveloperFields(string $messageName, array $fields, array $devFields): self
    {
        $msgDef = self::messageDef($messageName);

        $present = [];
        foreach ($fields as $name => $value) {
            $fdef = self::fieldDefByName($msgDef, $name);
            if ($fdef === null) {
                throw new \InvalidArgumentException("Unknown field '{$name}' on message '{$messageName}'");
            }
            $present[$fdef->fieldDefNum] = [
                'def'   => $fdef,
                'size'  => self::fieldSize($fdef, $value),
                'value' => $value,
            ];
        }
        ksort($present);

        // Cache the definition (native + developer field shape) so repeated
        // records reuse one definition instead of re-emitting it each call.
        $cacheKey = $messageName . '|';
        foreach ($present as $fid => $info) {
            $cacheKey .= $fid . ':' . $info['size'] . ',';
        }
        $cacheKey .= '|dev|';
        foreach ($devFields as $dev) {
            $cacheKey .= $dev['fieldNum'] . ':' . $dev['baseType']->size() . ':' . $dev['devDataIndex'] . ',';
        }

        if (!isset($this->defCache[$cacheKey])) {
            $localType = $this->nextLocalType;
            $this->nextLocalType = ($this->nextLocalType + 1) & 0x0F;
            $this->defCache[$cacheKey] = $localType;

            // Definition with the developer-data flag set.
            $def = self::byte(0x40 | 0x20 | $localType) . "\x00" . "\x00"
                . pack('v', $msgDef->globalNum) . self::byte(count($present));
            foreach ($present as $info) {
                $def .= self::byte($info['def']->fieldDefNum) . self::byte($info['size']) . self::byte($info['def']->baseType->value);
            }
            $def .= self::byte(count($devFields));
            foreach ($devFields as $dev) {
                $def .= self::byte($dev['fieldNum']) . self::byte($dev['baseType']->size()) . self::byte($dev['devDataIndex']);
            }
            $this->body .= $def;
        }
        $localType = $this->defCache[$cacheKey];

        // Data: native fields then developer fields, in declaration order.
        $this->body .= self::byte($localType);
        foreach ($present as $info) {
            $this->body .= self::encodeValue($info['def'], $info['size'], $info['value']);
        }
        foreach ($devFields as $dev) {
            $this->body .= self::packBase($dev['baseType'], $dev['value']);
        }
        return $this;
    }

    public function toBytes(): string
    {
        $dataSize = strlen($this->body);
        // 12-byte header (no header CRC).
        $header = chr(0x0C)
            . chr(0x10)              // protocol version 2.0
            . pack('v', 2178)        // profile version 21.78 (matches recent SDK)
            . pack('V', $dataSize)
            . '.FIT';
        $payload = $header . $this->body;
        return $payload . pack('v', Crc16::of($payload));
    }

    public function writeTo(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory: {$dir}");
        }
        if (file_put_contents($path, $this->toBytes()) === false) {
            throw new \RuntimeException("Cannot write to: {$path}");
        }
    }

    private static function messageDef(string $name): MessageDef
    {
        $global = self::GLOBAL_NUMS[$name] ?? null;
        if ($global === null) {
            throw new \InvalidArgumentException(
                "Writer doesn't know message '{$name}'. Add its global number to Writer::GLOBAL_NUMS."
            );
        }
        $m = Profile::message($global);
        if ($m === null) {
            throw new \RuntimeException("Profile is missing message {$name} (global {$global})");
        }
        return $m;
    }

    private static function fieldDefByName(MessageDef $m, string $name): ?FieldDef
    {
        foreach ($m->fields as $f) {
            if ($f->name === $name) {
                return $f;
            }
        }
        return null;
    }

    /**
     * @param array<int, array{def: FieldDef, size: int, value: mixed}> $present
     */
    private static function definitionBytes(int $localType, MessageDef $msg, array $present): string
    {
        $out = self::byte(0x40 | $localType)   // definition header
            . "\x00"                           // reserved
            . "\x00"                           // arch: 0 = little-endian
            . pack('v', $msg->globalNum)
            . self::byte(count($present));
        foreach ($present as $info) {
            $out .= self::byte($info['def']->fieldDefNum)
                .  self::byte($info['size'])
                .  self::byte($info['def']->baseType->value);
        }
        return $out;
    }

    /** Single byte, masked to 0-255 so the value satisfies chr()'s int<0,255>. */
    private static function byte(int $n): string
    {
        return chr($n & 0xFF);
    }

    private static function fieldSize(FieldDef $def, mixed $value): int
    {
        if ($def->baseType === BaseType::String) {
            // Fixed-width null-padded; pick a length now that fits the value.
            if (is_string($value) && $value !== '') {
                return strlen($value) + 1;
            }
            return 1; // just a null sentinel byte
        }
        return $def->baseType->size();
    }

    private static function encodeValue(FieldDef $def, int $size, mixed $value): string
    {
        $base = $def->baseType;

        if ($base === BaseType::String) {
            $str = is_string($value) ? $value : '';
            return str_pad(substr($str, 0, $size - 1), $size, "\0");
        }

        if ($value === null) {
            return self::sentinelBytes($base, $size);
        }

        // date_time → seconds since FIT epoch (uint32).
        if ($def->typeName === 'date_time') {
            if (!$value instanceof \DateTimeInterface) {
                throw new \InvalidArgumentException(
                    "Field {$def->name} (date_time) expects DateTimeInterface, got "
                    . get_debug_type($value)
                );
            }
            $sec = $value->getTimestamp() - FitTimestamp::FIT_EPOCH_OFFSET;
            return pack('V', $sec & 0xFFFFFFFF);
        }

        // Semicircles → degrees * 2^31 / 180 (signed int32).
        if ($def->units === 'semicircles') {
            if (!is_int($value) && !is_float($value)) {
                throw new \InvalidArgumentException(
                    "Field {$def->name} (semicircles) expects numeric degrees"
                );
            }
            $raw = (int) round(((float) $value) * (2 ** 31) / 180.0);
            return pack('V', $raw & 0xFFFFFFFF);
        }

        // Enum label → resolved int.
        if ($def->typeName !== null && is_string($value)) {
            $resolved = Profile::enumValue($def->typeName, $value);
            if ($resolved === null) {
                throw new \InvalidArgumentException(
                    "Unknown enum label '{$value}' for type '{$def->typeName}' on field {$def->name}"
                );
            }
            $value = $resolved;
        }

        // Inverse of Decoder::applyProfile() scale/offset.
        if (is_int($value) || is_float($value)) {
            if ($def->scale !== 0.0 && ($def->scale !== 1.0 || $def->offset !== 0.0)) {
                $value = ($value + $def->offset) * $def->scale;
            }
        }

        return self::packBase($base, $value);
    }

    private static function packBase(BaseType $base, mixed $value): string
    {
        return match ($base) {
            BaseType::Enum, BaseType::Uint8, BaseType::Uint8z, BaseType::Byte
                => chr(((int) $value) & 0xFF),
            BaseType::Sint8
                => pack('c', (int) $value),
            BaseType::Uint16, BaseType::Uint16z, BaseType::Sint16
                => pack('v', ((int) $value) & 0xFFFF),
            BaseType::Uint32, BaseType::Uint32z, BaseType::Sint32
                => pack('V', ((int) $value) & 0xFFFFFFFF),
            BaseType::Float32
                => pack('g', (float) $value),
            BaseType::Float64
                => pack('e', (float) $value),
            BaseType::Sint64, BaseType::Uint64, BaseType::Uint64z
                => pack('P', (int) $value),
            BaseType::String
                => throw new \LogicException('strings encoded separately'),
        };
    }

    private static function sentinelBytes(BaseType $base, int $size): string
    {
        $sentinel = $base->invalidSentinel();
        if ($sentinel === null) {
            // String / non-applicable.
            return str_repeat("\0", $size);
        }
        return self::packBase($base, $sentinel);
    }
}
