<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\Export\CsvWriter;
use Emontis\FitReader\FitReader;
use Emontis\FitReader\Geo\EncodedPolyline;

$session = FitReader::activity(DEMO_FIT_PATH)->sessions[0];

demo_title('12', 'Custom report — reusing the lower-level building blocks');

// You don't have to go through the exporters. Below are two public helpers the
// library uses internally, handy for building your own outputs.

// --- 1) Per-kilometer splits, rendered with CsvWriter::buildMatrix() ----------
// buildMatrix() projects a list of field-dicts onto a fixed, string-formatted
// column set (nulls → '', floats rounded, etc.) — exactly what the CSV exporter
// feeds its writer. We reuse it here to lay out an aligned ASCII table.
// (It orders string columns alphabetically, hence the a/b/… key prefixes.)
$rows = [];
foreach ($session->laps as $i => $lap) {
    $dist = $lap->totalDistance() ?? 0.0;
    $secs = $lap->totalTimerTime() ?? $lap->totalElapsedTime() ?? 0.0;
    $pace = $dist > 0.0 ? $secs / ($dist / 1000.0) : 0.0;
    $rows[] = [
        'a_km'     => $i + 1,
        'b_dist'   => round($dist / 1000.0, 2),
        'c_time'   => gmdate('i:s', (int) round($secs)),
        'd_pace'   => gmdate('i:s', (int) round($pace)) . '/km',
        'e_avg_hr' => $lap->field('avg_heart_rate'),
        'f_max_hr' => $lap->field('max_heart_rate'),
    ];
}

$matrix  = CsvWriter::buildMatrix($rows);
$headers = ['Km', 'Dist km', 'Time', 'Pace', 'Avg HR', 'Max HR'];

// Column widths from headers + every formatted cell.
$widths = array_map('strlen', $headers);
foreach ($matrix['rows'] as $row) {
    foreach ($row as $c => $cell) {
        $widths[$c] = max($widths[$c], strlen($cell));
    }
}
$render = static function (array $cells) use ($widths): string {
    $out = [];
    foreach ($cells as $c => $cell) {
        $out[] = str_pad($cell, $widths[$c]);
    }
    return '  ' . implode('  ', $out);
};

echo $render($headers), "\n";
echo $render(array_map(static fn (int $w): string => str_repeat('─', $w), $widths)), "\n";
foreach ($matrix['rows'] as $row) {
    echo $render($row), "\n";
}

// --- 2) The track as a Google-encoded polyline (EncodedPolyline) --------------
// This is the compact string the Mapbox Static Images API accepts as a path-
// overlay; it's how the PNG renderer draws the route.
$points = [];
foreach ($session->records as $r) {
    $p = $r->position();
    if ($p !== null) {
        $points[] = [$p->lat, $p->lng];
    }
}
$encoded = EncodedPolyline::encode($points);

printf("\nEncoded polyline (%d points → %d chars):\n", count($points), strlen($encoded));
echo '  ', strlen($encoded) > 76 ? substr($encoded, 0, 73) . '…' : $encoded, "\n";
