<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Unit\Value;

use Emontis\FitReader\Value\FitTimestamp;
use PHPUnit\Framework\TestCase;

final class FitTimestampTest extends TestCase
{
    public function testFitEpochIsTheFitEpoch(): void
    {
        $t = (new FitTimestamp(0))->toDateTimeImmutable();
        self::assertSame('1989-12-31T00:00:00+00:00', $t->format('c'));
    }

    public function testKnownTimestamp(): void
    {
        // 631065600 seconds since FIT epoch = unix 1262131200 = 2009-12-30 00:00:00 UTC
        $t = (new FitTimestamp(631065600))->toDateTimeImmutable();
        self::assertSame('2009-12-30T00:00:00+00:00', $t->format('c'));
    }
}
