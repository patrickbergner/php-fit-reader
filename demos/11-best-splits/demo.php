<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\FitReader;

$session = FitReader::activity(DEMO_FIT_PATH)->sessions[0];

demo_title('11', 'Best splits — fastest 1 km / 5 km from the data, no export needed');

// "Best efforts" are a pure read-the-objects analysis: every record already
// carries a cumulative distance() and a timestamp(), so the fastest stretch of
// any length is a sliding window over those two series. This is the kind of
// thing you'd do on the Activity directly rather than round-tripping a file.

$mmss = static fn (float $s): string => sprintf('%d:%02d', intdiv((int) round($s), 60), ((int) round($s)) % 60);

// Collect (cumulative metres, seconds-from-start) for records that have both.
$dist = [];
$time = [];
$t0   = null;
foreach ($session->records as $r) {
    $d = $r->distance();
    $t = $r->timestamp();
    if ($d === null || $t === null) {
        continue;
    }
    $t0 ??= $t;
    $dist[] = $d;
    $time[] = (float) ($t->getTimestamp() - $t0->getTimestamp());
}

/**
 * Fastest contiguous stretch covering at least $target metres: an O(n) sliding
 * window over the monotonic distance/time series. Returns [seconds, startOffset].
 *
 * @param list<float> $dist
 * @param list<float> $time
 * @return array{0: float, 1: float}|null
 */
$fastest = static function (array $dist, array $time, float $target): ?array {
    $n = count($dist);
    $best = INF;
    $bestStart = 0.0;
    $i = 0;
    for ($j = 0; $j < $n; $j++) {
        while ($i < $j && $dist[$j] - $dist[$i] >= $target) {
            $elapsed = $time[$j] - $time[$i];
            if ($elapsed < $best) {
                $best = $elapsed;
                $bestStart = $time[$i];
            }
            $i++;
        }
    }
    return is_finite($best) ? [$best, $bestStart] : null;
};

$total    = end($dist) ?: 0.0;
$duration = end($time) ?: 0.0;

printf("Total: %.2f km in %s  (avg %s/km)\n\n",
    $total / 1000.0,
    $mmss($duration),
    $mmss($duration / ($total / 1000.0)),
);

printf("  %-10s  %-7s  %-9s  %s\n", 'Effort', 'Time', 'Pace', 'Starts at');
echo '  ', str_repeat('─', 44), "\n";
foreach ([400 => '400 m', 1000 => '1 km', 5000 => '5 km', 10000 => '10 km'] as $metres => $label) {
    if ($total < $metres) {
        continue; // run shorter than this effort
    }
    [$secs, $start] = $fastest($dist, $time, (float) $metres);
    printf("  %-10s  %-7s  %-9s  %s into the run\n",
        $label,
        $mmss($secs),
        $mmss($secs / ($metres / 1000.0)) . '/km',
        $mmss($start),
    );
}

echo "\n(Each effort covers at least that distance — the window snaps to record boundaries.)\n";
