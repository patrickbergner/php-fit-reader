<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\FitReader;

// The simplest read: decode a .fit file into a typed Activity object.
$activity = FitReader::activity(DEMO_FIT_PATH);
$session  = $activity->sessions[0];

demo_title('01', 'Quick start');

printf("Sport:    %s\n", $session->sport());
printf("Distance: %.2f km\n", $session->totalDistance() / 1000.0);
printf("Duration: %.0f min\n", $session->totalTimerTime() / 60.0);
printf("Avg HR:   %s bpm\n", $session->avgHeartRate() ?? '—');
