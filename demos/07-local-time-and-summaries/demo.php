<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\FitReader;

$activity = FitReader::activity(DEMO_FIT_PATH);
$session  = $activity->sessions[0];

demo_title('07', 'Local time, moving time & session averages');

// FIT timestamps are UTC. The activity also records the athlete's wall-clock,
// so the library can recover the local time zone. Sessions know moving vs.
// elapsed time and carry averaged running dynamics.

$mmss = static fn (?float $s): string => $s === null
    ? '—'
    : sprintf('%d:%02d', intdiv((int) round($s), 60), ((int) round($s)) % 60);
$offset = $activity->utcOffsetSeconds;

echo "Time — FIT stores UTC; local is recovered from the activity's offset\n";
printf("  created (UTC)         %s\n", $activity->timeCreated?->format('Y-m-d H:i:s') ?? '—');
printf("  UTC offset            %s\n", $offset === null ? '—' : sprintf('%+d s  (%+.0f h)', $offset, $offset / 3600));
printf("  created (local)       %s\n", $activity->localTimeCreated?->format('Y-m-d H:i:s P') ?? '—');
printf("  session start (local) %s\n", $activity->toLocalTime($session->startTime)?->format('H:i:s P') ?? '—');

echo "\nMoving vs. elapsed time\n";
printf("  elapsed (wall-clock)  %s\n", $mmss($session->totalElapsedTime));
printf("  timer (moving)        %s\n", $mmss($session->totalTimerTime));
printf("  movingTime()          %s\n", $mmss($session->movingTime()));
printf("  stoppedTime()         %s\n", $mmss($session->stoppedTime()));

echo "\nSession-average running dynamics\n";
printf("  avg vertical oscillation  %s mm\n", $session->avgVerticalOscillation ?? '—');
printf("  avg stance time           %s ms\n", $session->avgStanceTime ?? '—');
printf("  avg step length           %s mm\n", $session->avgStepLength ?? '—');
