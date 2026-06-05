<?php

declare(strict_types=1);

namespace Emontis\FitReader\Export;

use Emontis\FitReader\Activity\Activity;
use Emontis\FitReader\Activity\Record;
use Emontis\FitReader\Activity\Session;
use Emontis\FitReader\Geo\TrackPoints;

/**
 * Converts an Activity into a GeoJSON (RFC 7946) FeatureCollection for web maps
 * (Leaflet / MapLibre / Mapbox GL / OpenLayers) and GIS tools.
 *
 * One `LineString` Feature per session, coordinates in GeoJSON's **`[lng, lat]`**
 * order. It is geometry-first: the route plus a little session-level metadata in
 * `properties` (sport, session number) — enough to draw the line and fit the map
 * to it. Sessions with fewer than two GPS fixes are omitted, since a LineString
 * needs at least two positions.
 *
 * Unlike the GPX/KML/TCX writers this needs no `xmlwriter` — it is plain
 * `json_encode`.
 *
 * The caller controls which records are drawn per session via the
 * `$resolveRecords` callback (`fn(Session): iterable<Record>`); the default is
 * the session's raw records. See {@see \Emontis\FitReader\FitReader::activityToGeoJson()}.
 */
final class GeoJsonWriter
{
    /**
     * @param (callable(Session): iterable<Record>)|null $resolveRecords
     */
    public function toString(Activity $activity, ?callable $resolveRecords = null): string
    {
        $resolveRecords ??= static fn (Session $s): array => $s->records;

        $features = [];
        foreach ($activity->sessions as $i => $session) {
            $points = TrackPoints::of($resolveRecords($session));
            if (count($points) < 2) {
                continue; // a GeoJSON LineString needs at least two positions
            }

            $coordinates = [];
            foreach ($points as [$lat, $lng]) {
                $coordinates[] = [$lng, $lat]; // GeoJSON order is lng,lat
            }

            $properties = ['session' => $i + 1];
            $sport = $session->sport();
            if ($sport !== null) {
                $properties['sport'] = $sport;
            }

            $features[] = [
                'type'       => 'Feature',
                'properties' => $properties,
                'geometry'   => [
                    'type'        => 'LineString',
                    'coordinates' => $coordinates,
                ],
            ];
        }

        return json_encode(
            ['type' => 'FeatureCollection', 'features' => $features],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param (callable(Session): iterable<Record>)|null $resolveRecords
     */
    public function writeFile(Activity $activity, string $path, ?callable $resolveRecords = null): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create GeoJSON target directory: {$dir}");
        }
        $bytes = file_put_contents($path, $this->toString($activity, $resolveRecords));
        if ($bytes === false) {
            throw new \RuntimeException("Cannot write GeoJSON to: {$path}");
        }
    }
}
