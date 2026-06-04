<?php

declare(strict_types=1);

namespace Emontis\FitReader\Activity;

use Emontis\FitReader\Message\Message;
use Emontis\FitReader\Value\GeoPoint;

/**
 * Snapshot of one `record` message. Common accessors are typed; the full
 * resolved field set is available via `field()` and `all()`.
 */
final readonly class Record
{
    /** @param array<string|int, mixed> $fields */
    public function __construct(private array $fields)
    {
    }

    public static function fromMessage(Message $m): self
    {
        return new self($m->fields);
    }

    public function timestamp(): ?\DateTimeImmutable
    {
        $v = $this->fields['timestamp'] ?? null;
        return $v instanceof \DateTimeImmutable ? $v : null;
    }

    public function position(): ?GeoPoint
    {
        $v = $this->fields['position'] ?? null;
        return $v instanceof GeoPoint ? $v : null;
    }

    public function distance(): ?float
    {
        return self::asFloat($this->fields['distance'] ?? null);
    }

    public function speed(): ?float
    {
        return self::asFloat($this->fields['speed'] ?? null);
    }

    public function altitude(): ?float
    {
        return self::asFloat($this->fields['altitude'] ?? null)
            ?? self::asFloat($this->fields['enhanced_altitude'] ?? null);
    }

    public function heartRate(): ?int
    {
        $v = $this->fields['heart_rate'] ?? null;
        return is_int($v) ? $v : null;
    }

    public function cadence(): ?int
    {
        $v = $this->fields['cadence'] ?? null;
        return is_int($v) ? $v : null;
    }

    public function power(): ?int
    {
        $v = $this->fields['power'] ?? null;
        return is_int($v) ? $v : null;
    }

    public function temperature(): ?int
    {
        $v = $this->fields['temperature'] ?? null;
        return is_int($v) ? $v : null;
    }

    public function depth(): ?float
    {
        return self::asFloat($this->fields['depth'] ?? null);
    }

    public function grade(): ?float
    {
        return self::asFloat($this->fields['grade'] ?? null);
    }

    public function gpsAccuracy(): ?int
    {
        $v = $this->fields['gps_accuracy'] ?? null;
        return is_int($v) ? $v : null;
    }

    public function field(string $name, mixed $default = null): mixed
    {
        return $this->fields[$name] ?? $default;
    }

    /** @return array<string|int, mixed> */
    public function all(): array
    {
        return $this->fields;
    }

    private static function asFloat(mixed $v): ?float
    {
        if (is_float($v)) return $v;
        if (is_int($v))   return (float) $v;
        return null;
    }
}
