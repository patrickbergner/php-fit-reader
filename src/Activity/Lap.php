<?php

declare(strict_types=1);

namespace Emontis\FitReader\Activity;

use Emontis\FitReader\Message\Message;

final class Lap
{
    /**
     * @param array<string|int, mixed> $summary
     * @param list<Record> $records
     */
    public function __construct(
        public readonly array $summary,
        public readonly array $records,
    ) {
    }

    /**
     * @param list<Record> $records
     */
    public static function fromMessage(Message $m, array $records): self
    {
        return new self($m->fields, $records);
    }

    public ?string $sport {
        get {
            $v = $this->summary['sport'] ?? null;
            return is_string($v) ? $v : null;
        }
    }

    public ?\DateTimeImmutable $startTime {
        get {
            $v = $this->summary['start_time'] ?? null;
            return $v instanceof \DateTimeImmutable ? $v : null;
        }
    }

    public ?float $totalDistance {
        get => self::asFloat($this->summary['total_distance'] ?? null);
    }

    public ?float $totalTimerTime {
        get => self::asFloat($this->summary['total_timer_time'] ?? null);
    }

    public ?float $totalElapsedTime {
        get => self::asFloat($this->summary['total_elapsed_time'] ?? null);
    }

    public function field(string $name, mixed $default = null): mixed
    {
        return $this->summary[$name] ?? $default;
    }

    private static function asFloat(mixed $v): ?float
    {
        if (is_float($v)) return $v;
        if (is_int($v))   return (float) $v;
        return null;
    }
}
