<?php

declare(strict_types=1);

namespace Emontis\FitReader\Geo;

use Emontis\FitReader\Value\GeoPoint;

/**
 * Axis-aligned geographic bounding box of a set of points — the minimal
 * `lat`/`lng` rectangle that contains them all.
 *
 * This is the framing primitive map providers ask for: a static-image API
 * needs it to pick a center and zoom, a web map (Leaflet/MapLibre/OpenLayers)
 * passes it straight to `fitBounds`. Build it from a track with
 * {@see fromPoints()} (or {@see \Emontis\FitReader\FitReader::trackBounds()}).
 */
final readonly class BoundingBox
{
    public function __construct(
        public float $minLat,
        public float $minLng,
        public float $maxLat,
        public float $maxLng,
    ) {
    }

    /**
     * @param list<array{0: float, 1: float}> $points  lat,lng pairs.
     * @return self|null  Null when $points is empty (no box to compute).
     */
    public static function fromPoints(array $points): ?self
    {
        if ($points === []) {
            return null;
        }

        [$minLat, $minLng] = $points[0];
        $maxLat = $minLat;
        $maxLng = $minLng;
        foreach ($points as [$lat, $lng]) {
            if ($lat < $minLat) $minLat = $lat;
            if ($lat > $maxLat) $maxLat = $lat;
            if ($lng < $minLng) $minLng = $lng;
            if ($lng > $maxLng) $maxLng = $lng;
        }

        return new self($minLat, $minLng, $maxLat, $maxLng);
    }

    /** Geometric center of the box. */
    public function center(): GeoPoint
    {
        return new GeoPoint(
            ($this->minLat + $this->maxLat) / 2.0,
            ($this->minLng + $this->maxLng) / 2.0,
        );
    }
}
