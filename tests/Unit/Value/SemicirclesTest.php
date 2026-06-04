<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Unit\Value;

use Emontis\FitReader\Value\Semicircles;
use PHPUnit\Framework\TestCase;

final class SemicirclesTest extends TestCase
{
    public function testZero(): void
    {
        self::assertSame(0.0, Semicircles::degreesOf(0));
    }

    public function testMaxPositiveIsCloseToOneEighty(): void
    {
        // 2^31 - 1 semicircles, scale 180 / 2^31 → just under 180.
        $deg = Semicircles::degreesOf(2147483647);
        self::assertEqualsWithDelta(180.0, $deg, 1e-6);
        self::assertLessThan(180.0, $deg);
    }

    public function testNegativeIsMinusOneEighty(): void
    {
        self::assertSame(-180.0, Semicircles::degreesOf(-2147483648));
    }

    public function testLeipzigLatitude(): void
    {
        // Leipzig ≈ 51.34°N. Round-trip through semicircles.
        $semis = (int) round(51.34 / (180.0 / 2147483648));
        self::assertEqualsWithDelta(51.34, Semicircles::degreesOf($semis), 1e-6);
    }
}
