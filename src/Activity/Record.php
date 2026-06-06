<?php

declare(strict_types=1);

namespace Emontis\FitReader\Activity;

use Emontis\FitReader\Message\Message;
use Emontis\FitReader\Value\GeoPoint;

/**
 * Snapshot of one `record` message. Common accessors are typed; the full
 * resolved field set is available via `field()` and `all()`.
 */
final class Record
{
    /** @param array<string|int, mixed> $fields */
    public function __construct(private readonly array $fields)
    {
    }

    public static function fromMessage(Message $m): self
    {
        return new self($m->fields);
    }

    public ?\DateTimeImmutable $timestamp {
        get {
            $v = $this->fields['timestamp'] ?? null;
            return $v instanceof \DateTimeImmutable ? $v : null;
        }
    }

    public ?GeoPoint $position {
        get {
            $v = $this->fields['position'] ?? null;
            return $v instanceof GeoPoint ? $v : null;
        }
    }

    public ?float $distance {
        get => self::asFloat($this->fields['distance'] ?? null);
    }

    public ?float $speed {
        get => self::asFloat($this->fields['speed'] ?? null);
    }

    public ?float $altitude {
        get => self::asFloat($this->fields['altitude'] ?? null)
            ?? self::asFloat($this->fields['enhanced_altitude'] ?? null);
    }

    public ?int $heartRate {
        get {
            $v = $this->fields['heart_rate'] ?? null;
            return is_int($v) ? $v : null;
        }
    }

    public ?int $cadence {
        get {
            $v = $this->fields['cadence'] ?? null;
            return is_int($v) ? $v : null;
        }
    }

    public ?int $power {
        get {
            $v = $this->fields['power'] ?? null;
            return is_int($v) ? $v : null;
        }
    }

    public ?int $temperature {
        get {
            $v = $this->fields['temperature'] ?? null;
            return is_int($v) ? $v : null;
        }
    }

    public ?float $depth {
        get => self::asFloat($this->fields['depth'] ?? null);
    }

    public ?float $grade {
        get => self::asFloat($this->fields['grade'] ?? null);
    }

    public ?int $gpsAccuracy {
        get {
            $v = $this->fields['gps_accuracy'] ?? null;
            return is_int($v) ? $v : null;
        }
    }

    // --- Running dynamics ----------------------------------------------------

    /** Vertical oscillation in millimetres. */
    public ?float $verticalOscillation {
        get => self::asFloat($this->fields['vertical_oscillation'] ?? null);
    }

    /** Ground-contact (stance) time in milliseconds. */
    public ?float $stanceTime {
        get => self::asFloat($this->fields['stance_time'] ?? null);
    }

    /** Stance time as a percent of the stride. */
    public ?float $stanceTimePercent {
        get => self::asFloat($this->fields['stance_time_percent'] ?? null);
    }

    /** Left/right ground-contact-time balance, in percent. */
    public ?float $stanceTimeBalance {
        get => self::asFloat($this->fields['stance_time_balance'] ?? null);
    }

    /** Vertical ratio (vertical oscillation ÷ step length), in percent. */
    public ?float $verticalRatio {
        get => self::asFloat($this->fields['vertical_ratio'] ?? null);
    }

    /** Step length in millimetres. */
    public ?float $stepLength {
        get => self::asFloat($this->fields['step_length'] ?? null);
    }

    /** Fractional cadence (the sub-integer part), in rpm. */
    public ?float $fractionalCadence {
        get => self::asFloat($this->fields['fractional_cadence'] ?? null);
    }

    /** Respiration rate in breaths/min (prefers the enhanced field). */
    public ?float $respirationRate {
        get => self::asFloat($this->fields['enhanced_respiration_rate'] ?? null)
            ?? self::asFloat($this->fields['respiration_rate'] ?? null);
    }

    // --- Cycling dynamics ----------------------------------------------------

    /** Raw left/right power balance field (high bit flags the reference side). */
    public ?int $leftRightBalance {
        get {
            $v = $this->fields['left_right_balance'] ?? null;
            return is_int($v) ? $v : null;
        }
    }

    /** Left/right pedal torque effectiveness, in percent. */
    public ?float $leftTorqueEffectiveness {
        get => self::asFloat($this->fields['left_torque_effectiveness'] ?? null);
    }

    public ?float $rightTorqueEffectiveness {
        get => self::asFloat($this->fields['right_torque_effectiveness'] ?? null);
    }

    /** Left/right/combined pedal smoothness, in percent. */
    public ?float $leftPedalSmoothness {
        get => self::asFloat($this->fields['left_pedal_smoothness'] ?? null);
    }

    public ?float $rightPedalSmoothness {
        get => self::asFloat($this->fields['right_pedal_smoothness'] ?? null);
    }

    public ?float $combinedPedalSmoothness {
        get => self::asFloat($this->fields['combined_pedal_smoothness'] ?? null);
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
