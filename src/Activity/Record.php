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

    // --- Running dynamics ----------------------------------------------------

    /** Vertical oscillation in millimetres. */
    public function verticalOscillation(): ?float
    {
        return self::asFloat($this->fields['vertical_oscillation'] ?? null);
    }

    /** Ground-contact (stance) time in milliseconds. */
    public function stanceTime(): ?float
    {
        return self::asFloat($this->fields['stance_time'] ?? null);
    }

    /** Stance time as a percent of the stride. */
    public function stanceTimePercent(): ?float
    {
        return self::asFloat($this->fields['stance_time_percent'] ?? null);
    }

    /** Left/right ground-contact-time balance, in percent. */
    public function stanceTimeBalance(): ?float
    {
        return self::asFloat($this->fields['stance_time_balance'] ?? null);
    }

    /** Vertical ratio (vertical oscillation ÷ step length), in percent. */
    public function verticalRatio(): ?float
    {
        return self::asFloat($this->fields['vertical_ratio'] ?? null);
    }

    /** Step length in millimetres. */
    public function stepLength(): ?float
    {
        return self::asFloat($this->fields['step_length'] ?? null);
    }

    /** Fractional cadence (the sub-integer part), in rpm. */
    public function fractionalCadence(): ?float
    {
        return self::asFloat($this->fields['fractional_cadence'] ?? null);
    }

    /** Respiration rate in breaths/min (prefers the enhanced field). */
    public function respirationRate(): ?float
    {
        return self::asFloat($this->fields['enhanced_respiration_rate'] ?? null)
            ?? self::asFloat($this->fields['respiration_rate'] ?? null);
    }

    // --- Cycling dynamics ----------------------------------------------------

    /** Raw left/right power balance field (high bit flags the reference side). */
    public function leftRightBalance(): ?int
    {
        $v = $this->fields['left_right_balance'] ?? null;
        return is_int($v) ? $v : null;
    }

    /** Left/right pedal torque effectiveness, in percent. */
    public function leftTorqueEffectiveness(): ?float
    {
        return self::asFloat($this->fields['left_torque_effectiveness'] ?? null);
    }

    public function rightTorqueEffectiveness(): ?float
    {
        return self::asFloat($this->fields['right_torque_effectiveness'] ?? null);
    }

    /** Left/right/combined pedal smoothness, in percent. */
    public function leftPedalSmoothness(): ?float
    {
        return self::asFloat($this->fields['left_pedal_smoothness'] ?? null);
    }

    public function rightPedalSmoothness(): ?float
    {
        return self::asFloat($this->fields['right_pedal_smoothness'] ?? null);
    }

    public function combinedPedalSmoothness(): ?float
    {
        return self::asFloat($this->fields['combined_pedal_smoothness'] ?? null);
    }

    public function field(string $name, mixed $default = null): mixed
    {
        return $this->fields[$name] ?? $default;
    }

    /**
     * Read a developer (Connect IQ) field by its declared name, e.g.
     * `developerField('Stryd Power')`. Resolved developer fields share the
     * same store as {@see field()}; this signals intent. Returns $default when
     * the field is absent (or stayed raw bytes under `dev_field_N` because no
     * `field_description` described it).
     */
    public function developerField(string $name, mixed $default = null): mixed
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
