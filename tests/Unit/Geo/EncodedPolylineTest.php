<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Unit\Geo;

use Emontis\FitReader\Geo\EncodedPolyline;
use PHPUnit\Framework\TestCase;

final class EncodedPolylineTest extends TestCase
{
    public function testGoogleReferenceVector(): void
    {
        // Canonical example from
        // https://developers.google.com/maps/documentation/utilities/polylinealgorithm
        $points = [
            [38.5, -120.2],
            [40.7, -120.95],
            [43.252, -126.453],
        ];
        self::assertSame('_p~iF~ps|U_ulLnnqC_mqNvxq`@', EncodedPolyline::encode($points));
    }

    public function testEmpty(): void
    {
        self::assertSame('', EncodedPolyline::encode([]));
    }

    public function testSinglePoint(): void
    {
        // Single point: just the delta vs (0,0).
        $encoded = EncodedPolyline::encode([[38.5, -120.2]]);
        self::assertSame('_p~iF~ps|U', $encoded);
    }
}
