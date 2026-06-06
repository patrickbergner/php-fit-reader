<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Unit\Activity;

use Emontis\FitReader\Activity\ContinuousTimelineNormalizer;
use Emontis\FitReader\Activity\Record;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContinuousTimelineNormalizerTest extends TestCase
{
    /**
     * Three consecutive 1-second records; the middle one carries the value
     * under test for the `zeroIsInvalid` field `power`.
     *
     * @return Record[]
     */
    private static function recordsWithMiddlePower(float $middle): array
    {
        return [
            new Record(['timestamp' => new \DateTimeImmutable('@1000'), 'power' => 100.0]),
            new Record(['timestamp' => new \DateTimeImmutable('@1001'), 'power' => $middle]),
            new Record(['timestamp' => new \DateTimeImmutable('@1002'), 'power' => 200.0]),
        ];
    }

    /** @return Record[] */
    private static function normalizeMiddlePower(float $middle): array
    {
        return new ContinuousTimelineNormalizer(zeroIsInvalid: ['power'])
            ->normalize(self::recordsWithMiddlePower($middle));
    }

    /** @return iterable<string, array{float}> */
    public static function nearZeroValues(): iterable
    {
        yield 'exact zero'         => [0.0];
        yield 'small positive'     => [0.03];
        yield 'small negative'     => [-0.04];
        yield 'upper boundary'     => [0.05];
        yield 'lower boundary'     => [-0.05];
    }

    /**
     * A `zeroIsInvalid` field whose magnitude is at or below 0.05 counts as
     * "no signal": the reading is dropped and the previous valid value carried.
     */
    #[DataProvider('nearZeroValues')]
    public function testNearZeroIsTreatedAsMissingAndForwardFilled(float $middle): void
    {
        $out = self::normalizeMiddlePower($middle);

        self::assertCount(3, $out);
        self::assertSame(100.0, $out[1]->field('power'), 'near-zero reading should be dropped and 100.0 carried forward');
    }

    /** @return iterable<string, array{float}> */
    public static function outsideThresholdValues(): iterable
    {
        yield 'just above positive' => [0.06];
        yield 'just below negative' => [-0.06];
        yield 'clearly non-zero'    => [42.0];
    }

    /** A value further from zero than 0.05 is a real measurement and kept as-is. */
    #[DataProvider('outsideThresholdValues')]
    public function testValuesOutsideThresholdAreKept(float $middle): void
    {
        $out = self::normalizeMiddlePower($middle);

        self::assertCount(3, $out);
        self::assertSame($middle, $out[1]->field('power'));
    }

    /** The near-zero rule only applies to fields listed in `zeroIsInvalid`. */
    public function testNearZeroIsKeptForFieldsNotMarkedZeroInvalid(): void
    {
        // Default zeroIsInvalid is ['heart_rate'] — `power` is not covered here.
        $out = new ContinuousTimelineNormalizer()
            ->normalize(self::recordsWithMiddlePower(0.03));

        self::assertSame(0.03, $out[1]->field('power'));
    }
}
