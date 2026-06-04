<?php

declare(strict_types=1);

namespace Emontis\FitReader\Activity;

use Emontis\FitReader\Message\Message;

final readonly class Lap
{
    /**
     * @param array<string|int, mixed> $summary
     * @param Record[] $records
     */
    public function __construct(
        public array $summary,
        public array $records,
    ) {
    }

    public static function fromMessage(Message $m, array $records): self
    {
        return new self($m->fields, $records);
    }

    public function sport(): ?string
    {
        $v = $this->summary['sport'] ?? null;
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
