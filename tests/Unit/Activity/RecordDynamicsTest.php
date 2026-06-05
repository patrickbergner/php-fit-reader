<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Unit\Activity;

use Emontis\FitReader\Activity\Record;
use PHPUnit\Framework\TestCase;

/**
 * The running/cycling dynamics accessors are thin reads over already-scaled
 * profile fields, so the fixtures use the post-decode (scaled) values.
 */
final class RecordDynamicsTest extends TestCase
{
    public function testRunningDynamicsAccessors(): void
    {
        $r = new Record([
            'vertical_oscillation' => 85.9,
            'stance_time'          => 234.0,
            'stance_time_percent'  => 33.0,
            'stance_time_balance'  => 49.8,
            'vertical_ratio'       => 8.69,
            'step_length'          => 988.0,
            'fractional_cadence'   => 0.5,
        ]);

        self::assertSame(85.9, $r->verticalOscillation());
        self::assertSame(234.0, $r->stanceTime());
        self::assertSame(33.0, $r->stanceTimePercent());
        self::assertSame(49.8, $r->stanceTimeBalance());
        self::assertSame(8.69, $r->verticalRatio());
        self::assertSame(988.0, $r->stepLength());
        self::assertSame(0.5, $r->fractionalCadence());
    }

    public function testRespirationPrefersEnhancedField(): void
    {
        $both = new Record(['enhanced_respiration_rate' => 24.2, 'respiration_rate' => 20.0]);
        self::assertSame(24.2, $both->respirationRate());

        $legacy = new Record(['respiration_rate' => 20.0]);
        self::assertSame(20.0, $legacy->respirationRate());
    }

    public function testCyclingDynamicsAccessors(): void
    {
        $r = new Record([
            'left_right_balance'         => 178,
            'left_torque_effectiveness'  => 75.0,
            'right_torque_effectiveness' => 73.5,
            'left_pedal_smoothness'      => 22.0,
            'right_pedal_smoothness'     => 21.0,
            'combined_pedal_smoothness'  => 21.5,
        ]);

        self::assertSame(178, $r->leftRightBalance());
        self::assertSame(75.0, $r->leftTorqueEffectiveness());
        self::assertSame(73.5, $r->rightTorqueEffectiveness());
        self::assertSame(22.0, $r->leftPedalSmoothness());
        self::assertSame(21.0, $r->rightPedalSmoothness());
        self::assertSame(21.5, $r->combinedPedalSmoothness());
    }

    public function testMissingFieldsReturnNull(): void
    {
        $r = new Record([]);
        self::assertNull($r->verticalOscillation());
        self::assertNull($r->stanceTime());
        self::assertNull($r->respirationRate());
        self::assertNull($r->leftRightBalance());
        self::assertNull($r->combinedPedalSmoothness());
    }
}
