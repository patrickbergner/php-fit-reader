<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Unit\Geo;

use Emontis\FitReader\Geo\BoundingBox;
use PHPUnit\Framework\TestCase;

final class BoundingBoxTest extends TestCase
{
    public function testFromPointsComputesMinAndMax(): void
    {
        $box = BoundingBox::fromPoints([
            [52.50, 13.40],
            [52.52, 13.35],
            [52.48, 13.38],
        ]);

        self::assertNotNull($box);
        self::assertSame(52.48, $box->minLat);
        self::assertSame(52.52, $box->maxLat);
        self::assertSame(13.35, $box->minLng);
        self::assertSame(13.40, $box->maxLng);
    }

    public function testCenterIsTheMidpoint(): void
    {
        $box    = BoundingBox::fromPoints([[52.0, 13.0], [54.0, 15.0]]);
        $center = $box?->center();

        self::assertNotNull($center);
        self::assertSame(53.0, $center->lat);
        self::assertSame(14.0, $center->lng);
    }

    public function testEmptyPointsReturnNull(): void
    {
        self::assertNull(BoundingBox::fromPoints([]));
    }

    public function testSinglePointIsADegenerateBox(): void
    {
        $box = BoundingBox::fromPoints([[52.5, 13.37]]);

        self::assertNotNull($box);
        self::assertSame($box->minLat, $box->maxLat);
        self::assertSame($box->minLng, $box->maxLng);
        self::assertEquals(52.5, $box->center()->lat);
        self::assertEquals(13.37, $box->center()->lng);
    }
}
