<?php

declare(strict_types=1);

namespace Emontis\FitReader\Activity;

use Emontis\FitReader\Value\FitTimestamp;

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

    /**
     * Activity-wide UTC offset in seconds (e.g. 7200 for CEST), derived from
     * the `activity` summary's `local_timestamp` (local wall-clock) versus
     * `timestamp` (the same instant in UTC). Null when the file doesn't carry
     * a local timestamp. FIT timestamps are UTC; this recovers the local zone.
     */
    public function utcOffsetSeconds(): ?int
    {
        $summary = $this->summary;
        if ($summary === null) {
            return null;
        }
        $ts    = $summary['timestamp'] ?? null;
        $local = $summary['local_timestamp'] ?? null;
        if ($ts instanceof \DateTimeInterface && is_int($local)) {
            return $local - ($ts->getTimestamp() - FitTimestamp::FIT_EPOCH_OFFSET);
        }
        return null;
    }

    /** `timeCreated()` expressed in the activity's local time zone. */
    public function localTimeCreated(): ?\DateTimeImmutable
    {
        return $this->toLocalTime($this->timeCreated());
    }

    /**
     * Express any UTC instant from this activity (a session start, a record
     * timestamp, …) in its local time zone. Returns the value unchanged (UTC)
     * when no local offset is known, and null for a null input.
     */
    public function toLocalTime(?\DateTimeInterface $utc): ?\DateTimeImmutable
    {
        if ($utc === null) {
            return null;
        }
        $dt     = \DateTimeImmutable::createFromInterface($utc);
        $offset = $this->utcOffsetSeconds();
        return $offset === null ? $dt : $dt->setTimezone(self::offsetZone($offset));
    }

    private static function offsetZone(int $seconds): \DateTimeZone
    {
        $sign = $seconds < 0 ? '-' : '+';
        $abs  = abs($seconds);
        return new \DateTimeZone(sprintf('%s%02d:%02d', $sign, intdiv($abs, 3600), intdiv($abs % 3600, 60)));
    }
}
