<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\FitReader;

$activity = FitReader::activity(DEMO_FIT_PATH);

demo_title('02', 'Eager reading — sessions, records, positions');

foreach ($activity->sessions as $i => $session) {
    printf(
        "Session %d: %s, %.2f km, %d records\n",
        $i + 1,
        $session->sport,
        $session->totalDistance / 1000.0,
        count($session->records),
    );

    // Print every 600th record (~every 10 min) to keep the output short.
    foreach ($session->records as $n => $r) {
        if ($n % 600 !== 0) {
            continue;
        }
        $pos = $r->position; // GeoPoint|null (decimal degrees)
        printf(
            "  %s  %-18s  hr=%-3s cad=%-3s %.1f km/h\n",
            $r->timestamp?->format('H:i:s') ?? '—',
            $pos !== null ? sprintf('%.5f,%.5f', $pos->lat, $pos->lng) : 'no-fix',
            $r->heartRate ?? '—',
            $r->cadence ?? '—',
            ($r->speed ?? 0.0) * 3.6,
        );
    }
}
