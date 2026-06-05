<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\FitReader;

$session = FitReader::activity(DEMO_FIT_PATH)->sessions[0];

demo_title('13', 'Elevation & grade profile');

// altitude() + the cumulative distance() give you an elevation profile without
// any export. Raw GPS altitude is noisy, so we resample it onto fixed distance
// bins — that gives a clean profile to read grades from and to draw.

$binMetres = 25.0; // resample the altitude profile every 25 m

// Bin altitude by distance: average altitude within each $binMetres bucket.
$binAlt = [];
$binCnt = [];
$minAlt = INF;
$maxAlt = -INF;
foreach ($session->records as $r) {
    $d = $r->distance();
    $a = $r->altitude();
    if ($d === null || $a === null) {
        continue;
    }
    $bin = (int) floor($d / $binMetres);
    $binAlt[$bin] = ($binAlt[$bin] ?? 0.0) + $a;
    $binCnt[$bin] = ($binCnt[$bin] ?? 0) + 1;
    $minAlt = min($minAlt, $a);
    $maxAlt = max($maxAlt, $a);
}
ksort($binAlt);
$profile = [];
foreach ($binAlt as $bin => $sum) {
    $profile[] = ['dist' => $bin * $binMetres, 'alt' => $sum / $binCnt[$bin]];
}

// Steepest grade between consecutive bins (rise ÷ run).
$maxUp = 0.0;
$maxDown = 0.0;
for ($i = 1; $i < count($profile); $i++) {
    $dDist = $profile[$i]['dist'] - $profile[$i - 1]['dist'];
    if ($dDist > 0) {
        $grade = ($profile[$i]['alt'] - $profile[$i - 1]['alt']) / $dDist * 100.0;
        $maxUp   = max($maxUp, $grade);
        $maxDown = min($maxDown, $grade);
    }
}

printf("Distance:        %.2f km\n", (end($profile)['dist'] ?? 0.0) / 1000.0);
printf("Altitude:        %.1f m … %.1f m  (range %.1f m)\n", $minAlt, $maxAlt, $maxAlt - $minAlt);
printf("Ascent/descent:  +%s m / -%s m   (from the session summary)\n",
    $session->totalAscent() ?? '—',
    $session->totalDescent() ?? '—',
);
printf("Steepest grade:  +%.1f%% climb / %.1f%% descent  (over %dm bins)\n\n", $maxUp, $maxDown, (int) $binMetres);

// An ASCII elevation sparkline across the route.
$ramp = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];
$span = max($maxAlt - $minAlt, 0.001);
$cols = 60;
$step = max(1, (int) ceil(count($profile) / $cols));
$spark = '';
for ($i = 0; $i < count($profile); $i += $step) {
    $level = (int) round(($profile[$i]['alt'] - $minAlt) / $span * (count($ramp) - 1));
    $spark .= $ramp[$level];
}
echo "  ", $spark, "\n";
printf("  %.0f m%slow → high%s%.0f m\n", $minAlt, str_repeat(' ', 2), str_repeat(' ', max(0, mb_strlen($spark) - 20)), $maxAlt);
