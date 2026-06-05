<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\FitReader;

$session = FitReader::activity(DEMO_FIT_PATH)->sessions[0];

demo_title('09', 'Custom normalization — your own ContinuousTimelineNormalizer');

// FitReader::normalizer() is a shorthand for the constructor, so you only need
// to import FitReader. Every knob is available per call:
//   - a 30-second timeline instead of 1 second
//   - drop temperature/gps_accuracy columns
//   - treat a 0 heart rate as "no signal"
//   - don't forward-fill cadence (an instantaneous metric)
//   - add two GPS-derived columns
$normalizer = FitReader::normalizer(
    stepSeconds:   30,
    zeroIsInvalid: ['heart_rate'],
    noFill:        ['cadence'],
    exclude:       ['temperature', 'gps_accuracy'],
    derive: [
        'speed_kmh'       => FitReader::kilometersPerHourFromGps(30),
        'pace_min_per_km' => FitReader::minutesPerKilometerFromGps(30),
    ],
);

$rows = $normalizer->normalize($session->records);

printf("%-10s %-5s %-7s %-8s %-5s %s\n", 'Time', 'HR', 'km/h', 'min/km', 'Cad', 'Alt');
foreach (array_slice($rows, 0, 15) as $r) {
    printf(
        "%-10s %-5s %-7s %-8s %-5s %s\n",
        $r->timestamp()?->format('H:i:s') ?? '—',
        $r->heartRate() ?? '—',
        number_format((float) ($r->field('speed_kmh') ?? 0.0), 1),
        number_format((float) ($r->field('pace_min_per_km') ?? 0.0), 2),
        $r->cadence() ?? '—',
        number_format((float) ($r->altitude() ?? 0.0), 1),
    );
}
printf("… (%d rows total at a 30 s step)\n", count($rows));
