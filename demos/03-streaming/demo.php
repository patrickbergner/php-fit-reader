<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\FitReader;
use Emontis\FitReader\Message\MessageKind;

demo_title('03', 'Streaming — low-memory message iteration');

// messages() yields one Message at a time and never holds the whole file in
// memory; the file handle closes when the generator finishes. Ideal for huge
// files or when you only need a slice of the data.
$counts  = [];
$firstHr = null;
foreach (FitReader::messages(DEMO_FIT_PATH) as $msg) {
    $key          = $msg->name ?? "global #{$msg->globalNum}";
    $counts[$key] = ($counts[$key] ?? 0) + 1;

    if ($firstHr === null && $msg->kind === MessageKind::Record) {
        $firstHr = $msg->get('heart_rate');
    }
}

arsort($counts);
echo "Message counts by type:\n";
foreach ($counts as $name => $n) {
    printf("  %-14s %6d\n", $name, $n);
}
printf("\nFirst record heart rate: %s bpm\n", $firstHr ?? '—');
echo "Peak memory: " . round(memory_get_peak_usage(true) / 1024 / 1024, 1) . " MB\n";
