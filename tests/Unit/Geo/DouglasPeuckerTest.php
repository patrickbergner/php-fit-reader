<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Unit\Geo;

use Emontis\FitReader\Geo\DouglasPeucker;
use PHPUnit\Framework\TestCase;

final class DouglasPeuckerTest extends TestCase
{
    public function testStraightLineCollapsesToEndpoints(): void
    {
        $points = [
            [0.0, 0.0],
            [0.0, 1.0],
            [0.0, 2.0],
            [0.0, 3.0],
            [0.0, 4.0],
        ];
        $simplified = DouglasPeucker::simplify($points, 0.001);
        self::assertSame([[0.0, 0.0], [0.0, 4.0]], $simplified);
    }

    public function testSharpCornerIsKept(): void
    {
        $points = [
            [0.0, 0.0],
            [0.0, 1.0],
            [1.0, 1.0],   // sharp 90° corner — must survive
            [1.0, 2.0],
            [1.0, 3.0],
        ];
        $simplified = DouglasPeucker::simplify($points, 0.001);
        self::assertContains([1.0, 1.0], $simplified);
        // Endpoints always retained.
        self::assertSame([0.0, 0.0], $simplified[0]);
        self::assertSame([1.0, 3.0], $simplified[count($simplified) - 1]);
        self::assertLessThan(count($points), count($simplified));
    }

    public function testFewerThanThreePointsReturnedUnchanged(): void
    {
        self::assertSame([], DouglasPeucker::simplify([], 0.1));
        self::assertSame([[0.0, 0.0]], DouglasPeucker::simplify([[0.0, 0.0]], 0.1));
        $two = [[0.0, 0.0], [1.0, 1.0]];
        self::assertSame($two, DouglasPeucker::simplify($two, 0.1));
    }

    public function testZeroToleranceIsNoOp(): void
    {
        $points = [[0.0, 0.0], [0.0, 1.0], [0.0, 2.0]];
        self::assertSame($points, DouglasPeucker::simplify($points, 0.0));
    }

    public function testHigherToleranceRemovesMorePoints(): void
    {
        $points = [];
        for ($i = 0; $i <= 100; $i++) {
            // Sine wave: tightly curving, tolerance directly controls density.
            $points[] = [sin($i / 10.0) * 0.01, $i / 100.0];
        }
        $loose  = DouglasPeucker::simplify($points, 0.001);
        $tight  = DouglasPeucker::simplify($points, 0.00001);
        self::assertLessThan(count($tight), count($loose));
        self::assertLessThanOrEqual(count($points), count($tight));
    }
}
