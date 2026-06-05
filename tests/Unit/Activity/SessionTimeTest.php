<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Unit\Activity;

use Emontis\FitReader\Activity\Record;
use Emontis\FitReader\Activity\Session;
use PHPUnit\Framework\TestCase;

final class SessionTimeTest extends TestCase
{
    public function testAvgDynamicsFromSummary(): void
    {
        $s = new Session(
            [
                'avg_vertical_oscillation' => 86.0,
                'avg_stance_time'          => 240.0,
                'avg_stance_time_percent'  => 34.0,
                'avg_step_length'          => 990.0,
            ],
            [],
            [],
        );

        self::assertSame(86.0, $s->avgVerticalOscillation());
        self::assertSame(240.0, $s->avgStanceTime());
        self::assertSame(34.0, $s->avgStanceTimePercent());
        self::assertSame(990.0, $s->avgStepLength());

        $empty = new Session([], [], []);
        self::assertNull($empty->avgVerticalOscillation());
    }

    public function testMovingAndStoppedTimeFromSummary(): void
    {
        $s = new Session(
            ['total_timer_time' => 3000.0, 'total_elapsed_time' => 3300.0],
            [],
            [],
        );

        self::assertSame(3000.0, $s->movingTime());
        self::assertSame(300.0, $s->stoppedTime());
    }

    public function testMovingTimeDerivedFromRecordGapsWhenNoSummary(): void
    {
        // 0,1,2 then a 98 s pause gap, then 100,101,102.
        $records = [];
        foreach ([0, 1, 2, 100, 101, 102] as $t) {
            $records[] = new Record(['timestamp' => new \DateTimeImmutable("@{$t}")]);
        }
        $s = new Session([], [], $records);

        // Moving = the four 1 s steps; the 98 s gap (> 30 s default) is excluded.
        self::assertSame(4.0, $s->movingTime());
        // Elapsed span = 102 s; stopped = 102 - 4.
        self::assertSame(98.0, $s->stoppedTime());
    }

    public function testTimeMethodsNullWhenNothingToComputeFrom(): void
    {
        $s = new Session([], [], []);
        self::assertNull($s->movingTime());
        self::assertNull($s->stoppedTime());
    }
}
