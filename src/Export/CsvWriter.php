<?php

declare(strict_types=1);

namespace Emontis\FitReader\Export;

use Emontis\FitReader\Value\GeoPoint;

/**
 * Writes per-record streams to CSV. Callers prepare the row sets (raw,
 * normalized, or any custom projection) and pick file paths — this writer
 * makes no assumptions about an Activity, normalization, or naming. See
 * {@see FitReader::activityToCsv()} for the default raw + normalized recipe.
 *
 * The smaller per-message tables (file-id, file-creator, activity, sessions,
 * laps, device-info, events) aren't written here — consumers can build them
 * on demand from {@see buildMatrix()} (which is also what the integration
 * test uses to inline them as Markdown tables in its report).
 *
 * Column order is deterministic across runs: `session_index` first (when
 * the input carries it), then `timestamp`, then remaining string-keyed
 * names alphabetically, then numeric-keyed names (rendered `field_N`).
 */
final class CsvWriter
{
    /**
     * The CSV dialect. Defaults reproduce the historical output; override any
     * of them per instance (e.g. `new CsvWriter(separator: ';', eol: "\r\n")`).
     *
     * @param string $separator field delimiter (default `,`)
     * @param string $enclosure quoting character (default `"`)
     * @param string $escape    escape character; `''` (the default) means none,
     *                          so embedded enclosures are doubled per RFC 4180
     * @param string $eol       line ending (default `"\n"`)
     */
    public function __construct(
        private string $separator = ',',
        private string $enclosure = '"',
        private string $escape = '',
        private string $eol = "\n",
    ) {}

    /**
     * @param iterable<array<string|int, mixed>> $fieldDicts
     */
    public function writeRows(string $path, iterable $fieldDicts): void
    {
        $matrix = self::buildMatrix($fieldDicts);

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create CSV target directory: {$dir}");
        }
        $fh = fopen($path, 'wb');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open CSV for writing: {$path}");
        }
        try {
            $this->writeMatrix($fh, $matrix);
        } finally {
            fclose($fh);
        }
    }

    /**
     * Render the same CSV {@see writeRows()} would write, but return it as a
     * string instead of writing to a file.
     *
     * @param iterable<array<string|int, mixed>> $fieldDicts
     */
    public function toString(iterable $fieldDicts): string
    {
        $fh = fopen('php://temp', 'r+b');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open in-memory stream for CSV');
        }
        try {
            $this->writeMatrix($fh, self::buildMatrix($fieldDicts));
            rewind($fh);
            $csv = stream_get_contents($fh);
            if ($csv === false) {
                throw new \RuntimeException('Cannot read in-memory CSV stream');
            }
            return $csv;
        } finally {
            fclose($fh);
        }
    }

    /**
     * @param resource                                            $fh
     * @param array{cols: list<string>, rows: list<list<string>>} $matrix
     */
    private function writeMatrix($fh, array $matrix): void
    {
        fputcsv($fh, $matrix['cols'], $this->separator, $this->enclosure, $this->escape, $this->eol);
        foreach ($matrix['rows'] as $row) {
            fputcsv($fh, $row, $this->separator, $this->enclosure, $this->escape, $this->eol);
        }
    }

    /**
     * Project a list of field-dicts onto a fixed column set, returning the
     * column headers and the rows already formatted as strings (via
     * {@see formatCell()}). Same column-ordering rules as the CSV writer.
     * Exposed so other formatters (e.g. the integration test's Markdown
     * report) can reuse the projection without re-implementing it.
     *
     * @param iterable<array<string|int, mixed>> $fieldDicts
     * @return array{cols: list<string>, rows: list<list<string>>}
     */
    public static function buildMatrix(iterable $fieldDicts): array
    {
        $rows = is_array($fieldDicts) ? $fieldDicts : iterator_to_array($fieldDicts, false);

        $stringKeys = [];
        $intKeys = [];
        $hasSessionIndex = false;
        $hasTimestamp = false;
        foreach ($rows as $row) {
            foreach ($row as $k => $_v) {
                if ($k === 'session_index') {
                    $hasSessionIndex = true;
                } elseif ($k === 'timestamp') {
                    $hasTimestamp = true;
                } elseif (is_int($k)) {
                    $intKeys[$k] = true;
                } else {
                    $stringKeys[$k] = true;
                }
            }
        }
        $stringNames = array_keys($stringKeys);
        sort($stringNames);
        $intNames = array_keys($intKeys);
        sort($intNames);

        $columns = [];
        if ($hasSessionIndex) {
            $columns[] = 'session_index';
        }
        if ($hasTimestamp) {
            $columns[] = 'timestamp';
        }
        foreach ($stringNames as $n) {
            $columns[] = $n;
        }
        foreach ($intNames as $n) {
            $columns[] = 'field_' . $n;
        }

        $outRows = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                if ($col === 'session_index') {
                    $line[] = self::formatCell($row['session_index'] ?? null);
                } elseif ($col === 'timestamp') {
                    $line[] = self::formatCell($row['timestamp'] ?? null);
                } elseif (str_starts_with($col, 'field_')) {
                    $intKey = (int) substr($col, 6);
                    $line[] = self::formatCell($row[$intKey] ?? null);
                } else {
                    $line[] = self::formatCell($row[$col] ?? null);
                }
            }
            $outRows[] = $line;
        }

        return ['cols' => $columns, 'rows' => $outRows];
    }

    private static function formatCell(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('c');
        }
        if ($v instanceof GeoPoint) {
            return $v->lat . ',' . $v->lng;
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        if (is_string($v)) {
            return $v;
        }
        return (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
