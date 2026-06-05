<?php

declare(strict_types=1);

namespace Emontis\FitReader\Geo;

use Emontis\FitReader\Activity\Record;

/**
 * Flattens an iterable of {@see Record}s into the ordered `lat,lng` pairs every
 * geo tool here speaks — {@see EncodedPolyline}, {@see DouglasPeucker},
 * {@see BoundingBox}, and the Mapbox renderer all consume this shape.
 *
 * Records without a `position()` (indoor stretches, tunnels, sensor-only rows)
 * are skipped, so the result is the route as actually fixed by GPS.
 */
final class TrackPoints
{
    /**
     * @param iterable<Record> $records
     * @return list<array{0: float, 1: float}>  Ordered lat,lng pairs.
     */
    public static function of(iterable $records): array
    {
        $points = [];
        foreach ($records as $record) {
            $pos = $record->position();
            if ($pos !== null) {
                $points[] = [$pos->lat, $pos->lng];
            }
        }
        return $points;
    }
}
