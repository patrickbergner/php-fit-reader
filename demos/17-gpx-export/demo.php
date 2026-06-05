<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\FitReader;

$activity = FitReader::activity(DEMO_FIT_PATH);

demo_title('17', 'GPX 1.1 export — raw, normalized, and as a string');

// Raw records → GPX. Each <trkpt> carries Garmin's TrackPointExtension v1
// (hr/cad/atemp) plus a sibling <power>, so Strava / Garmin Connect / RideWithGPS
// auto-import the sensor channels on upload.
$rawPath = __DIR__ . '/tiergarten-raw.gpx';
FitReader::activityToGpx($activity, $rawPath, name: 'Tiergarten Run');
printf("raw GPX        → %s (%d bytes)\n", basename($rawPath), filesize($rawPath));

// Pass normalize: [...] to spread options into the ContinuousTimelineNormalizer.
// Derived fields (here km/h from GPS) are written into the trackpoint
// <extensions> under their own XML namespace to keep the GPX schema valid.
$normPath = __DIR__ . '/tiergarten-normalized.gpx';
FitReader::activityToGpx($activity, $normPath, name: 'Tiergarten Run', normalize: [
    'derive' => ['speed_kmh' => FitReader::kilometersPerHourFromGps(10)],
]);
printf("normalized GPX → %s (%d bytes)\n", basename($normPath), filesize($normPath));

// *ToGpxString() returns the XML without touching the filesystem.
$xml = FitReader::activityToGpxString($activity, name: 'Tiergarten Run');
printf("\nactivityToGpxString(): %d bytes. First lines:\n\n", strlen($xml));
foreach (array_slice(explode("\n", $xml), 0, 6) as $line) {
    echo '  ', $line, "\n";
}
