<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\FitReader;
use Emontis\FitReader\Geo\DouglasPeucker;
use Emontis\FitReader\Geo\EncodedPolyline;
use Emontis\FitReader\Geo\TrackPoints;

$activity = FitReader::activity(DEMO_FIT_PATH);
$session  = $activity->sessions[0];

demo_title('15', 'Visualization — bounding box, simplified track, and ready-to-use map links');

// The exporters write files; this is the other half — the small Geo toolkit that
// turns the track into exactly what a map provider wants:
//   • a bounding box / center            → frame a static map, or fitBounds() a web map
//   • an encoded polyline                → Mapbox & Google static-map path overlays
//   • GeoJSON                            → Leaflet / MapLibre / OpenLayers / geojson.io
// Simplify first (Ramer–Douglas–Peucker) to keep overlay URLs short.

// --- 1) Bounds & center -------------------------------------------------------
$bounds = FitReader::trackBounds($activity);
$center = $bounds?->center();
printf("Bounds:  %.5f, %.5f  →  %.5f, %.5f\n", $bounds->minLat, $bounds->minLng, $bounds->maxLat, $bounds->maxLng);
printf("Center:  %.5f, %.5f\n\n", $center->lat, $center->lng);

// --- 2) Simplify + encode -----------------------------------------------------
$points     = TrackPoints::of($session->records);
$tolerance  = 1e-4; // ~11 m — plenty for a map thumbnail
$simplified = DouglasPeucker::simplify($points, $tolerance);

$polyFull = FitReader::encodedPolyline($session);                       // every fix
$polyThin = FitReader::encodedPolyline($session, 5, $tolerance);        // simplified

printf("GPS fixes:        %d points\n", count($points));
printf("Simplified:       %d points  (%.1f%% fewer, ~11 m tolerance)\n",
    count($simplified), (1 - count($simplified) / max(1, count($points))) * 100);
printf("Encoded polyline: %d chars full → %d chars simplified\n\n", strlen($polyFull), strlen($polyThin));

// --- 3) Ready-made static-map URLs (drop in your own token/key) ---------------
// Both APIs speak the same Google-encoded polyline (precision 5).
$mapboxUrl = sprintf(
    'https://api.mapbox.com/styles/v1/mapbox/outdoors-v12/static/path-4+f44(%s)/auto/600x400?access_token=YOUR_MAPBOX_TOKEN',
    rawurlencode($polyThin),
);
$googleUrl = sprintf(
    'https://maps.googleapis.com/maps/api/staticmap?size=600x400&path=weight:4%%7Ccolor:red%%7Cenc:%s&key=YOUR_GOOGLE_KEY',
    rawurlencode($polyThin),
);
echo "Mapbox Static Images URL:\n  ", $mapboxUrl, "\n\n";
echo "Google Static Maps URL:\n  ", $googleUrl, "\n\n";

// --- 4) A keyless link that actually draws the track --------------------------
// geojson.io reads GeoJSON straight from its URL hash — no API key needed. We
// send the simplified track so the link stays short.
$feature = [
    'type'       => 'Feature',
    'properties' => ['name' => 'Tiergarten Run (simplified)'],
    'geometry'   => [
        'type'        => 'LineString',
        'coordinates' => array_map(static fn (array $p): array => [$p[1], $p[0]], $simplified), // [lng, lat]
    ],
];
$geojsonIo = 'https://geojson.io/#data=data:application/json,'
    . rawurlencode(json_encode($feature, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
echo "Open the track in a keyless browser map (geojson.io):\n  ", $geojsonIo, "\n\n";

// --- 5) Render real Mapbox images, if a token is available --------------------
// Same token plumbing as the Mapbox demo. Two pictures: the track inside its
// bounding box, and the aggressively simplified track on its own.
$token = demo_mapbox_token();
if ($token === null) {
    echo "No Mapbox token — skipping the live renders (set MAPBOX_TOKEN to enable).\n\n";
} else {
    // The bounding box drawn as a closed rectangle overlay (5 corner points).
    $rect = [
        [$bounds->minLat, $bounds->minLng],
        [$bounds->minLat, $bounds->maxLng],
        [$bounds->maxLat, $bounds->maxLng],
        [$bounds->maxLat, $bounds->minLng],
        [$bounds->minLat, $bounds->minLng],
    ];
    $bboxPoly  = EncodedPolyline::encode($rect);
    $trackPoly = viz_fit_polyline($points); // most detail that still fits a GET URL

    $renders = [
        'tiergarten-bbox.png' => [
            'path-4+ea1d2c(' . rawurlencode($trackPoly) . ')',   // track, red
            'path-3+091aba(' . rawurlencode($bboxPoly) . ')',    // bounding box, blue
        ],
        'tiergarten-simplified.png' => [
            'path-4+ea1d2c(' . rawurlencode($polyThin) . ')',    // simplified track only
        ],
    ];
    foreach ($renders as $name => $overlays) {
        $url   = sprintf(
            'https://api.mapbox.com/styles/v1/mapbox/outdoors-v12/static/%s/auto/1280x1280@2x?access_token=%s&padding=60',
            implode(',', $overlays),
            rawurlencode($token),
        );
        $bytes = viz_fetch_image($url);
        if ($bytes === null) {
            printf("Render failed for %s (network/HTTP error).\n", $name);
            continue;
        }
        file_put_contents(__DIR__ . '/' . $name, $bytes);
        printf("Rendered → %s (%d bytes)\n", $name, strlen($bytes));
    }
    echo "\n";
}

// --- 6) GeoJSON for web map libraries -----------------------------------------
// One LineString Feature per session, [lng, lat] order — hand it to L.geoJSON()
// (Leaflet) or map.addSource() (MapLibre/Mapbox GL).
$geoJsonPath = __DIR__ . '/tiergarten.geojson';
FitReader::activityToGeoJson($activity, $geoJsonPath);
printf("GeoJSON written → %s (%d bytes)\n\n", basename($geoJsonPath), filesize($geoJsonPath));

$snippet = FitReader::activityToGeoJsonString($activity);
foreach (array_slice(explode("\n", $snippet), 0, 12) as $line) {
    echo '  ', $line, "\n";
}
echo "  …\n";


/**
 * Encode the track as a Google polyline simplified just enough to fit a GET URL.
 * Doubles the Douglas–Peucker tolerance until the URL-encoded polyline is under
 * a safe budget — the same trick the Mapbox renderer uses internally.
 *
 * @param list<array{0: float, 1: float}> $points lat,lng pairs
 */
function viz_fit_polyline(array $points, float $tolerance = 1e-5, int $budget = 6500): string
{
    $encoded = '';
    for ($pass = 0; $pass < 12; $pass++) {
        $encoded = EncodedPolyline::encode(DouglasPeucker::simplify($points, $tolerance));
        if (strlen(rawurlencode($encoded)) <= $budget) {
            return $encoded;
        }
        $tolerance *= 2.0;
    }
    return $encoded; // best effort after the last pass
}

/** Fetch a static-map image; null on any non-image / HTTP failure (never fatal). */
function viz_fetch_image(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $type   = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

    return (is_string($body) && $status === 200 && str_starts_with($type, 'image/')) ? $body : null;
}
