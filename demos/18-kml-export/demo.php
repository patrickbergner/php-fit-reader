<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\FitReader;

$activity = FitReader::activity(DEMO_FIT_PATH);

demo_title('18', 'KML export (Google Earth) — raw, normalized, and as a string');

// Raw records → KML. A time-animatable <gx:Track> per session (parallel <when>
// and <gx:coord> lists — note KML's lon,lat,alt order), styled with a red
// <LineStyle>. Heart rate and cadence ride along as <gx:SimpleArrayData> arrays,
// the channels Google Earth can surface.
$rawPath = __DIR__ . '/tiergarten-raw.kml';
FitReader::activityToKml($activity, $rawPath, name: 'Tiergarten Run');
printf("raw KML        → %s (%d bytes)\n", basename($rawPath), filesize($rawPath));

// Normalization works the same as the other exporters (per session).
$normPath = __DIR__ . '/tiergarten-normalized.kml';
FitReader::activityToKml($activity, $normPath, name: 'Tiergarten Run', normalize: true);
printf("normalized KML → %s (%d bytes)\n", basename($normPath), filesize($normPath));

// *ToKmlString() returns the XML without touching the filesystem.
$xml = FitReader::activityToKmlString($activity, name: 'Tiergarten Run');
printf("\nactivityToKmlString(): %d bytes. First lines:\n\n", strlen($xml));
foreach (array_slice(explode("\n", $xml), 0, 8) as $line) {
    echo '  ', $line, "\n";
}
