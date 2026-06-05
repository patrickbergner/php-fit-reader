<?php

declare(strict_types=1);

namespace Emontis\FitReader\Activity;

use Emontis\FitReader\Message\Message;

final class Session
{
    /** Lazily-computed; not readonly so it can be cached on first access. */
    private ?array $normalizedCache = null;

    /**
     * @param array<string|int, mixed> $summary
     * @param Lap[]    $laps
     * @param Record[] $records  flattened across all laps for convenience
     */
    public function __construct(
        public readonly array $summary,
        public readonly array $laps,
        public readonly array $records,
    ) {
    }

    public static function build(Message $m, array $laps, array $allRecords): self
    {
        return new self($m->fields, $laps, $allRecords);
    }

    public function sport(): ?string
    {
        $v = $this->summary['sport'] ?? null;
        return is_string($v) ? $v : null;
    }

    public function subSport(): ?string
    {
        $v = $this->summary['sub_sport'] ?? null;
        return is_string($v) ? $v : null;
    }

    public function startTime(): ?\DateTimeImmutable
    {
        $v = $this->summary['start_time'] ?? null;
        return $v instanceof \DateTimeImmutable ? $v : null;
    }

    public function totalDistance(): ?float
    {
        return self::asFloat($this->summary['total_distance'] ?? null);
    }

    public function totalTimerTime(): ?float
    {
        return self::asFloat($this->summary['total_timer_time'] ?? null);
    }

    public function totalElapsedTime(): ?float
    {
        return self::asFloat($this->summary['total_elapsed_time'] ?? null);
    }

    public function avgHeartRate(): ?int
    {
        $v = $this->summary['avg_heart_rate'] ?? null;
        if (is_int($v) && $v > 0) {
            return $v;
        }
        return $this->aggregateRaw('heart_rate', 'avg', zeroIsInvalid: true);
    }

    public function maxHeartRate(): ?int
    {
        $v = $this->summary['max_heart_rate'] ?? null;
        if (is_int($v) && $v > 0) {
            return $v;
        }
        return $this->aggregateRaw('heart_rate', 'max', zeroIsInvalid: true);
    }

    public function avgCadence(): ?int
    {
        $v = $this->summary['avg_cadence'] ?? null;
        if (is_int($v)) {
            return $v;
        }
        return $this->aggregateRaw('cadence', 'avg', zeroIsInvalid: false);
    }

    public function maxCadence(): ?int
    {
        $v = $this->summary['max_cadence'] ?? null;
        if (is_int($v)) {
            return $v;
        }
        return $this->aggregateRaw('cadence', 'max', zeroIsInvalid: false);
    }

    public function avgPower(): ?int
    {
        $v = $this->summary['avg_power'] ?? null;
        if (is_int($v)) {
            return $v;
        }
        return $this->aggregateRaw('power', 'avg', zeroIsInvalid: false);
    }

    public function maxPower(): ?int
    {
        $v = $this->summary['max_power'] ?? null;
        if (is_int($v)) {
            return $v;
        }
        return $this->aggregateRaw('power', 'max', zeroIsInvalid: false);
    }

    public function totalCalories(): ?int
    {
        $v = $this->summary['total_calories'] ?? null;
        return is_int($v) ? $v : null;
    }

    public function totalAscent(): ?int
    {
        $v = $this->summary['total_ascent'] ?? null;
        return is_int($v) ? $v : null;
    }

    public function totalDescent(): ?int
    {
        $v = $this->summary['total_descent'] ?? null;
        return is_int($v) ? $v : null;
    }

    /** Average vertical oscillation in millimetres. */
    public function avgVerticalOscillation(): ?float
    {
        return self::asFloat($this->summary['avg_vertical_oscillation'] ?? null);
    }

    /** Average ground-contact (stance) time in milliseconds. */
    public function avgStanceTime(): ?float
    {
        return self::asFloat($this->summary['avg_stance_time'] ?? null);
    }

    /** Average stance time as a percent of the stride. */
    public function avgStanceTimePercent(): ?float
    {
        return self::asFloat($this->summary['avg_stance_time_percent'] ?? null);
    }

    /** Average step length in millimetres. */
    public function avgStepLength(): ?float
    {
        return self::asFloat($this->summary['avg_step_length'] ?? null);
    }

    /**
     * Active ("moving") time in seconds: the clock with pauses removed. Uses
     * the device's `total_timer_time` when present; otherwise derives it from
     * the record cadence, counting gaps larger than $maxGapSeconds (auto-pause)
     * as stopped rather than moving. Null when neither is available.
     */
    public function movingTime(float $maxGapSeconds = 30.0): ?float
    {
        $timer = $this->totalTimerTime();
        if ($timer !== null) {
            return $timer;
        }

        $moving = 0.0;
        $prev   = null;
        $any    = false;
        foreach ($this->records as $r) {
            $t = $r->timestamp()?->getTimestamp();
            if ($t === null) {
                continue;
            }
            if ($prev !== null && $t > $prev) {
                $any = true;
                $gap = (float) ($t - $prev);
                if ($gap <= $maxGapSeconds) {
                    $moving += $gap;
                }
            }
            $prev = $t;
        }
        return $any ? $moving : null;
    }

    /**
     * Stopped (paused) time in seconds: elapsed wall-clock minus moving time.
     * Uses `total_elapsed_time` when present, else the record time span. Null
     * when it can't be determined.
     */
    public function stoppedTime(float $maxGapSeconds = 30.0): ?float
    {
        $elapsed = $this->totalElapsedTime() ?? $this->recordSpanSeconds();
        $moving  = $this->movingTime($maxGapSeconds);
        if ($elapsed === null || $moving === null) {
            return null;
        }
        return max(0.0, $elapsed - $moving);
    }

    /** Wall-clock span between the first and last timestamped record, in seconds. */
    private function recordSpanSeconds(): ?float
    {
        $first = null;
        $last  = null;
        foreach ($this->records as $r) {
            $t = $r->timestamp()?->getTimestamp();
            if ($t === null) {
                continue;
            }
            $first ??= $t;
            $last = $t;
        }
        return ($first !== null && $last !== null && $last >= $first) ? (float) ($last - $first) : null;
    }

    public function field(string $name, mixed $default = null): mixed
    {
        return $this->summary[$name] ?? $default;
    }

    /**
     * Records collapsed onto a 1-second timeline with forward-filled fields.
     * See {@see ContinuousTimelineNormalizer} for the rules.
     *
     * @return Record[]
     */
    public function recordsNormalized(): array
    {
        return $this->normalizedCache ??= (new ContinuousTimelineNormalizer())->normalize($this->records);
    }

    private function aggregateRaw(string $field, string $reducer, bool $zeroIsInvalid): ?int
    {
        $sum = 0;
        $max = null;
        $count = 0;
        foreach ($this->records as $r) {
            $v = $r->field($field);
            if (!is_int($v) && !is_float($v)) {
                continue;
            }
            if ($zeroIsInvalid && $v == 0) {
                continue;
            }
            $count++;
            $sum += $v;
            if ($max === null || $v > $max) {
                $max = $v;
            }
        }
        if ($count === 0) {
            return null;
        }
        if ($reducer === 'max') {
            return (int) $max;
        }
        return (int) round($sum / $count);
    }

    private static function asFloat(mixed $v): ?float
    {
        if (is_float($v)) return $v;
        if (is_int($v))   return (float) $v;
        return null;
    }
}
