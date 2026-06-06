<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\FitReader;

$session = FitReader::activity(DEMO_FIT_PATH)->sessions[0];

demo_title('10', 'GPS-derived speed & pace');

// Five factories turn GPS position deltas into speed/pace, each taking an
// optional trailing smoothing window in seconds to tame jitter:
//   metersPerSecondFromGps · kilometersPerHourFromGps · milesPerHourFromGps
//   minutesPerKilometerFromGps · minutesPerMileFromGps
$normalizer = FitReader::normalizer(
    derive: [
        'kmh'   => FitReader::kilometersPerHourFromGps(15),
        'mph'   => FitReader::milesPerHourFromGps(15),
        'minkm' => FitReader::minutesPerKilometerFromGps(15),
        'minmi' => FitReader::minutesPerMileFromGps(15),
    ],
);

$rows = $normalizer->normalize($session->records);

printf("%-10s %-7s %-7s %-9s %s\n", 'Time', 'km/h', 'mph', 'min/km', 'min/mi');
foreach ($rows as $n => $r) {
    if ($n % 300 !== 0) { // every ~5 min
        continue;
    }
    printf(
        "%-10s %-7s %-7s %-9s %s\n",
        $r->timestamp?->format('H:i:s') ?? '—',
        number_format((float) ($r->field('kmh') ?? 0.0), 1),
        number_format((float) ($r->field('mph') ?? 0.0), 1),
        number_format((float) ($r->field('minkm') ?? 0.0), 2),
        number_format((float) ($r->field('minmi') ?? 0.0), 2),
    );
}

// The derivers are stateful (they track GPS history for smoothing).
// For an Activity with several Sessions, wrap each with FitReader::perRun
// so it starts fresh per session:
//
//   FitReader::normalizer(derive: [
//       'kmh' => FitReader::perRun(fn () => FitReader::kilometersPerHourFromGps(15)),
//   ]);
