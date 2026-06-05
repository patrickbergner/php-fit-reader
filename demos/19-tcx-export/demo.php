<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\FitReader;

$activity = FitReader::activity(DEMO_FIT_PATH);

demo_title('19', 'TCX export (Garmin Training Center) — raw, normalized, and as a string');

// Raw records → TCX. Unlike GPX, TCX is lap- and summary-aware: one <Activity>
// per session, one <Lap> per lap with its aggregates, and native HR / Cadence /
// Distance / Altitude trackpoints. Power and speed land in the Garmin
// ActivityExtension namespace (ax:Watts / ax:Speed) — no XSD-violating sibling.
$rawPath = __DIR__ . '/tiergarten-raw.tcx';
FitReader::activityToTcx($activity, $rawPath);
printf("raw TCX        → %s (%d bytes)\n", basename($rawPath), filesize($rawPath));

// $normalize is applied per lap, so the trackpoints under each <Lap> are the
// continuous 1 second timeline. Add a GPS-derived speed and it flows into ax:Speed.
$normPath = __DIR__ . '/tiergarten-normalized.tcx';
FitReader::activityToTcx($activity, $normPath, normalize: [
    'derive' => ['speed_kmh' => FitReader::perRun(fn () => FitReader::kilometersPerHourFromGps(10))],
]);
printf("normalized TCX → %s (%d bytes)\n", basename($normPath), filesize($normPath));

// *ToTcxString() returns the XML without touching the filesystem.
$xml = FitReader::activityToTcxString($activity);
printf("\nactivityToTcxString(): %d bytes. First lines:\n\n", strlen($xml));
foreach (array_slice(explode("\n", $xml), 0, 8) as $line) {
    echo '  ', $line, "\n";
}
