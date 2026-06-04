<?php

declare(strict_types=1);

namespace Emontis\FitReader\Activity;

final readonly class Activity
{
    /**
     * @param array<string|int, mixed>      $fileId       raw file_id fields
     * @param array<string|int, mixed>|null $fileCreator  raw file_creator fields
     * @param Session[]                     $sessions
     * @param array<int, array<string|int, mixed>> $deviceInfos
     * @param array<int, array<string|int, mixed>> $events
     * @param array<string|int, mixed>|null $summary      raw activity message fields
     */
    public function __construct(
        public array $fileId,
        public ?array $fileCreator,
        public array $sessions,
        public array $deviceInfos,
        public array $events,
        public ?array $summary,
    ) {
    }

    public function manufacturer(): ?string
    {
        $v = $this->fileId['manufacturer'] ?? null;
        return is_string($v) ? $v : (is_int($v) ? (string) $v : null);
    }

    public function timeCreated(): ?\DateTimeImmutable
    {
        $v = $this->fileId['time_created'] ?? null;
        return $v instanceof \DateTimeImmutable ? $v : null;
    }

    public function numSessions(): int
    {
        return count($this->sessions);
    }

    public function totalTimerTime(): ?float
    {
        $v = $this->summary['total_timer_time'] ?? null;
        if (is_float($v)) return $v;
        if (is_int($v))   return (float) $v;
        return null;
    }
}
