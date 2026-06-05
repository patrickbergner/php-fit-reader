<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Emontis\FitReader\FitReader;

$activity = FitReader::activity(DEMO_FIT_PATH);

demo_title('16', 'CSV export — raw, normalized, and as a string');

// One CSV row per output record; columns are the union of all fields, so a
// sparse writer still produces a rectangular table.
$rawPath = __DIR__ . '/tiergarten-raw.csv';
FitReader::activityToCsv($activity, $rawPath);
printf("raw CSV        → %s (%d bytes)\n", basename($rawPath), filesize($rawPath));

// normalize: true collapses the stream onto the default forward-filled 1 second
// timeline before writing — handy for downstream tools that want dense rows.
$normPath = __DIR__ . '/tiergarten-normalized.csv';
FitReader::activityToCsv($activity, $normPath, normalize: true);
printf("normalized CSV → %s (%d bytes)\n", basename($normPath), filesize($normPath));

// *ToCsvString() returns the CSV text directly.
$csv   = FitReader::activityToCsvString($activity);
$lines = explode("\n", trim($csv));
printf("\nactivityToCsvString(): %d data rows.\n\n", count($lines) - 1);
echo "  header: ", $lines[0], "\n";
echo "  row 1:  ", $lines[1], "\n";

// The CSV dialect is overridable via csv: [...] — separator, enclosure, escape
// and eol. Here: a semicolon-delimited, CRLF file. Defaults reproduce the output above.
$euroPath = __DIR__ . '/tiergarten-semicolon.csv';
FitReader::activityToCsv($activity, $euroPath, normalize: true, csv: ['separator' => ';', 'eol' => "\r\n"]);
printf("\nsemicolon CSV  → %s (%d bytes)\n", basename($euroPath), filesize($euroPath));
echo "  header: ", explode("\n", FitReader::activityToCsvString($activity, csv: ['separator' => ';']))[0], "\n";
