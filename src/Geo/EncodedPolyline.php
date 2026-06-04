<?php

declare(strict_types=1);

namespace Emontis\FitReader\Geo;

/**
 * Google "Encoded Polyline" algorithm — the compact text encoding the
 * Mapbox Static Images API accepts as a `path-` overlay. Precision 5
 * is what Mapbox expects (precision 6 is also valid but requires the
 * `enc:` prefix, which we don't use).
 *
 * @see https://developers.google.com/maps/documentation/utilities/polylinealgorithm
 */
final class EncodedPolyline
{
    /**
     * @param list<array{0: float, 1: float}> $points  Ordered lat,lng pairs.
     */
    public static function encode(array $points, int $precision = 5): string
    {
        $factor = 10 ** $precision;
        $prevLat = 0;
        $prevLng = 0;
        $out = '';
        foreach ($points as [$lat, $lng]) {
            $latI = (int) round($lat * $factor);
            $lngI = (int) round($lng * $factor);
            $out .= self::encodeSigned($latI - $prevLat);
            $out .= self::encodeSigned($lngI - $prevLng);
            $prevLat = $latI;
            $prevLng = $lngI;
        }
        return $out;
    }

    private static function encodeSigned(int $value): string
    {
        $v = $value < 0 ? ~($value << 1) : ($value << 1);
        $out = '';
        while ($v >= 0x20) {
            $out .= chr((0x20 | ($v & 0x1F)) + 63);
            $v >>= 5;
        }
        $out .= chr($v + 63);
        return $out;
    }
}
