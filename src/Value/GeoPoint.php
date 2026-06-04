<?php

declare(strict_types=1);

namespace Emontis\FitReader\Value;

final readonly class GeoPoint
{
    public function __construct(
        public float $lat,
        public float $lng,
    ) {
    }

    public static function fromSemicircles(int $latSemicircles, int $lngSemicircles): self
    {
        return new self(
            Semicircles::degreesOf($latSemicircles),
            Semicircles::degreesOf($lngSemicircles),
        );
    }
}
