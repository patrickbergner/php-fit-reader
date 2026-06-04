<?php

declare(strict_types=1);

namespace Emontis\FitReader\Geo;

/**
 * Ramer–Douglas–Peucker polyline simplification with perpendicular
 * distance measured in **degrees of arc**, not meters. Adequate for
 * thumbnail-map rendering, and avoids the haversine cost per inner
 * iteration. Iterative (stack-based) so input length is bounded only
 * by available memory, not the call stack.
 */
final class DouglasPeucker
{
    /**
     * @param list<array{0: float, 1: float}> $points  lat,lng pairs
     * @return list<array{0: float, 1: float}>
     */
    public static function simplify(array $points, float $toleranceDegrees): array
    {
        $n = count($points);
        if ($n < 3 || $toleranceDegrees <= 0.0) {
            return $points;
        }

        $keep = array_fill(0, $n, false);
        $keep[0] = true;
        $keep[$n - 1] = true;

        $tol2 = $toleranceDegrees * $toleranceDegrees;
        $stack = [[0, $n - 1]];
        while ($stack !== []) {
            [$lo, $hi] = array_pop($stack);
            if ($hi - $lo < 2) {
                continue;
            }
            [$lat1, $lng1] = $points[$lo];
            [$lat2, $lng2] = $points[$hi];
            $dLat = $lat2 - $lat1;
            $dLng = $lng2 - $lng1;
            $segLen2 = $dLat * $dLat + $dLng * $dLng;

            $maxD2 = 0.0;
            $maxI  = -1;
            for ($i = $lo + 1; $i < $hi; $i++) {
                [$lat, $lng] = $points[$i];
                if ($segLen2 == 0.0) {
                    $pLat = $lat - $lat1;
                    $pLng = $lng - $lng1;
                    $d2 = $pLat * $pLat + $pLng * $pLng;
                } else {
                    $num = ($lng - $lng1) * $dLat - ($lat - $lat1) * $dLng;
                    $d2  = ($num * $num) / $segLen2;
                }
                if ($d2 > $maxD2) {
                    $maxD2 = $d2;
                    $maxI  = $i;
                }
            }
            if ($maxI !== -1 && $maxD2 > $tol2) {
                $keep[$maxI] = true;
                $stack[] = [$lo, $maxI];
                $stack[] = [$maxI, $hi];
            }
        }

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            if ($keep[$i]) {
                $out[] = $points[$i];
            }
        }
        return $out;
    }
}
