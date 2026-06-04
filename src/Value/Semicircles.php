<?php

declare(strict_types=1);

namespace Emontis\FitReader\Value;

/**
 * FIT positions are encoded in "semicircles": a signed int32 covering the
 * full ±180° range. degrees = semicircles × (180 / 2^31).
 */
final readonly class Semicircles
{
    private const SCALE = 180.0 / 2147483648;

    public function __construct(public int $value)
    {
    }

    public function toDegrees(): float
    {
        return $this->value * self::SCALE;
    }

    public static function degreesOf(int $value): float
    {
        return $value * self::SCALE;
    }
}
