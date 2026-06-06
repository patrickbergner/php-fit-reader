<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\FitReader;

$session = FitReader::activity(DEMO_FIT_PATH)->sessions[0];

demo_title('05', 'Session totals and per-kilometer lap splits');

printf(
    "%s — %.2f km in %.0f min\n",
    ucfirst($session->sport),
    $session->totalDistance / 1000.0,
    $session->totalTimerTime / 60.0,
);
printf("HR avg/max:      %s / %s bpm\n", $session->avgHeartRate ?? '—', $session->maxHeartRate ?? '—');
printf("Cadence avg/max: %s / %s spm\n", $session->avgCadence ?? '—', $session->maxCadence ?? '—');
printf("Ascent/descent:  %s / %s m\n", $session->totalAscent ?? '—', $session->totalDescent ?? '—');
printf("Calories:        %s kcal\n", $session->totalCalories ?? '—');

printf("\n%-4s %-9s %-8s %-10s %s\n", 'Lap', 'Dist km', 'Time', 'Pace', 'Avg HR');
foreach ($session->laps as $i => $lap) {
    $dist = $lap->totalDistance ?? 0.0;
    $secs = $lap->totalTimerTime ?? $lap->totalElapsedTime ?? 0.0;
    $pace = $dist > 0.0 ? $secs / ($dist / 1000.0) : 0.0;
    printf(
        "%-4d %-9.2f %-8s %-10s %s\n",
        $i + 1,
        $dist / 1000.0,
        gmdate('i:s', (int) round($secs)),
        gmdate('i:s', (int) round($pace)) . '/km',
        // Lap exposes sensor aggregates through field() (no typed HR accessor).
        $lap->field('avg_heart_rate') ?? '—',
    );
}
