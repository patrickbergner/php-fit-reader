<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Unit\Activity;

use Emontis\FitReader\Activity\Activity;
use Emontis\FitReader\Value\FitTimestamp;
use PHPUnit\Framework\TestCase;

final class ActivityLocalTimeTest extends TestCase
{
    private const OFFSET = 7200; // +02:00

    public function testUtcOffsetAndLocalTimes(): void
    {
        $utc      = new \DateTimeImmutable('2026-06-01T10:00:00+00:00');
        $localFit = ($utc->getTimestamp() - FitTimestamp::FIT_EPOCH_OFFSET) + self::OFFSET;

        $activity = new Activity(
            fileId:      ['time_created' => $utc],
            fileCreator: null,
            sessions:    [],
            deviceInfos: [],
            events:      [],
            summary:     ['timestamp' => $utc, 'local_timestamp' => $localFit],
        );

        self::assertSame(self::OFFSET, $activity->utcOffsetSeconds);

        // Same instant, expressed in +02:00.
        self::assertSame('2026-06-01T12:00:00+02:00', $activity->localTimeCreated?->format('c'));
        self::assertSame('2026-06-01T12:00:00+02:00', $activity->toLocalTime($utc)?->format('c'));
        self::assertNull($activity->toLocalTime(null));
    }

    public function testNoLocalTimestampLeavesTimesUntouched(): void
    {
        $utc      = new \DateTimeImmutable('2026-06-01T10:00:00+00:00');
        $activity = new Activity(
            fileId:      ['time_created' => $utc],
            fileCreator: null,
            sessions:    [],
            deviceInfos: [],
            events:      [],
            summary:     null,
        );

        self::assertNull($activity->utcOffsetSeconds);
        // Falls back to the UTC instant unchanged.
        self::assertSame('2026-06-01T10:00:00+00:00', $activity->localTimeCreated?->format('c'));
    }
}
