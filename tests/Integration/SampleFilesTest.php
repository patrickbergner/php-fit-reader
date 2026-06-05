<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Integration;

use Emontis\FitReader\Activity\Activity;
use Emontis\FitReader\Activity\Session;
use Emontis\FitReader\Export\CsvWriter;
use Emontis\FitReader\FitReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Decode every .fit file in samples/, assert it produced data, and write
 * a per-sample Markdown report + GPX track to build/.
 */
final class SampleFilesTest extends TestCase
{
    private const ROOT_SAMPLE_DIR = __DIR__ . '/../../resources/samples';
    private const ROOT_BUILD_DIR  = __DIR__ . '/../../build/integration-tests';
    private const TOKEN_FILE      = __DIR__ . '/../../resources/mapbox-access-token.txt';

    public static function samples(): \Generator
    {
        $found = false;
        foreach (self::sourceDirs() as $key => [$sampleDir, $buildDir]) {
            $files = glob($sampleDir . '/*.fit') ?: [];
            sort($files);
            foreach ($files as $path) {
                $found = true;
                $label = $key === '' ? basename($path) : $key . '/' . basename($path);
                yield $label => [$path, $buildDir];
            }
        }
        if (!$found) {
            // Yield a placeholder so PHPUnit shows a skipped test rather
            // than failing with "data provider yielded no rows".
            yield 'no-samples' => [null, self::ROOT_BUILD_DIR];
        }
    }

    /**
     * @return array<string, array{0: string, 1: string}> label => [sampleDir, buildDir]
     */
    private static function sourceDirs(): array
    {
        $dirs = ['' => [self::ROOT_SAMPLE_DIR, self::ROOT_BUILD_DIR]];
        foreach (glob(self::ROOT_SAMPLE_DIR . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
            $name = basename($sub);
            $dirs[$name] = [$sub, self::ROOT_BUILD_DIR . '/' . $name];
        }
        return $dirs;
    }

    #[DataProvider('samples')]
    public function testSampleDecodesAndContainsData(?string $path, string $buildDir): void
    {
        if ($path === null) {
            self::markTestSkipped('No .fit files found in samples/');
        }

        @mkdir($buildDir, 0777, true);

        $activity = FitReader::activity($path);

        // "Contains data" — at least one session with at least one record.
        self::assertNotSame([], $activity->sessions, "{$path}: no sessions decoded");
        $totalRecords = 0;
        foreach ($activity->sessions as $session) {
            $totalRecords += count($session->records);
        }
        self::assertGreaterThan(0, $totalRecords, "{$path}: sessions contain no records");

        $base = pathinfo($path, PATHINFO_FILENAME);
        // Raw + normalized GPX and CSV, all through the public facade and symmetric.
        FitReader::activityToGpx($activity, $buildDir . "/{$base}-raw.gpx", name: $base);
        FitReader::activityToGpx(
            $activity,
            $buildDir . "/{$base}-normalized.gpx",
            name: $base,
            normalize: [
                'derive' => self::speedPaceDerivers()
            ],
        );

        FitReader::activityToCsv($activity, $buildDir . "/{$base}-raw.csv");
        FitReader::activityToCsv(
            $activity,
            $buildDir . "/{$base}-normalized.csv",
            normalize: [
                'exclude' => ['position', 'speed'],
                'derive' => self::speedPaceDerivers()
            ],
        );

        // TCX (lap-aware, fitness) and KML (visualization), raw + normalized,
        // mirroring the GPX/CSV pairs. The normalized TCX excludes native
        // `speed` so the GPS-derived speed flows into ax:Speed instead.
        FitReader::activityToTcx($activity, $buildDir . "/{$base}-raw.tcx");
        FitReader::activityToTcx(
            $activity,
            $buildDir . "/{$base}-normalized.tcx",
            normalize: [
                'exclude' => ['speed'],
                'derive' => self::speedPaceDerivers(),
            ],
        );

        FitReader::activityToKml($activity, $buildDir . "/{$base}-raw.kml", name: $base);
        FitReader::activityToKml($activity, $buildDir . "/{$base}-normalized.kml", name: $base, normalize: true);

        // GeoJSON (web maps: Leaflet/MapLibre), raw + normalized, mirroring the KML pair.
        FitReader::activityToGeoJson($activity, $buildDir . "/{$base}-raw.geojson");
        FitReader::activityToGeoJson($activity, $buildDir . "/{$base}-normalized.geojson", normalize: true);

        // Geo toolkit (bounding box + encoded polylines) dumped as plain text.
        file_put_contents($buildDir . "/{$base}-geo.txt", self::geoArtifact($activity));

        // PNG map generation is skipped for synthetic samples under
        // samples/default/ — those tracks are made-up coordinates that
        // would just burn Mapbox quota rendering noise.
        $pngPath    = $buildDir . "/{$base}.png";
        $pngWritten = false;
        $token      = self::mapboxToken();
        if ($token !== null && $buildDir === self::ROOT_BUILD_DIR) {
            try {
                $pngWritten = FitReader::activityToMapPng($activity, $pngPath, $token);
            } catch (\Throwable $e) {
                fwrite(STDERR, "[map png] {$base}: " . $e->getMessage() . "\n");
            }
        }

        $this->writeReport(
            $activity,
            $path,
            $buildDir . "/{$base}.md",
            $pngWritten ? $pngPath : null,
        );

        self::assertFileExists($buildDir . "/{$base}.md");
        self::assertFileExists($buildDir . "/{$base}-raw.gpx");
        self::assertFileExists($buildDir . "/{$base}-normalized.gpx");
        self::assertFileExists($buildDir . "/{$base}-raw.csv");
        self::assertFileExists($buildDir . "/{$base}-normalized.csv");
        self::assertFileExists($buildDir . "/{$base}-raw.tcx");
        self::assertFileExists($buildDir . "/{$base}-normalized.tcx");
        self::assertFileExists($buildDir . "/{$base}-raw.kml");
        self::assertFileExists($buildDir . "/{$base}-normalized.kml");
        self::assertFileExists($buildDir . "/{$base}-raw.geojson");
        self::assertFileExists($buildDir . "/{$base}-normalized.geojson");
        self::assertFileExists($buildDir . "/{$base}-geo.txt");
    }

    /**
     * Resolve the Mapbox access token: the `MAPBOX_TOKEN` environment variable
     * if set, otherwise `resources/mapbox-access-token.txt` (gitignored).
     * Returns null if neither is present/non-empty — the "skip PNG" branch.
     */
    private static function mapboxToken(): ?string
    {
        static $token = false; // false = not loaded, null = absent/empty, string = loaded
        if ($token === false) {
            $env = getenv('MAPBOX_TOKEN');
            if (is_string($env) && trim($env) !== '') {
                $token = trim($env);
            } else {
                $raw   = is_readable(self::TOKEN_FILE) ? @file_get_contents(self::TOKEN_FILE) : false;
                $token = ($raw === false) ? null : (trim($raw) ?: null);
            }
        }
        return $token;
    }

    /**
     * Fresh GPS speed/pace derivers for one export call. `speed_kmh` is wrapped
     * with `perRun()` so the normalizer rebuilds it per session (no smoothing
     * state leaks across a session boundary in multi-session files); `pace_min_per_km`
     * is a bare deriver — simpler, with a one-sample glitch at each later session's
     * start. Returned fresh each call so the GPX and CSV exports never share closures.
     *
     * @return array<string, mixed>
     */
    private static function speedPaceDerivers(): array
    {
        return [
            'speed_kmh'       => FitReader::perRun(fn() => FitReader::kilometersPerHourFromGps(10)),
            'pace_min_per_km' => FitReader::minutesPerKilometerFromGps(10),
        ];
    }

    /**
     * Plain-text dump of the Geo toolkit on a real track: the activity's
     * bounding box + center, then each session's GPS point count and encoded
     * polyline (full, plus a simplified one). Captures "everything we have" for
     * map prep on the sample. Gracefully reports "no GPS data" when a sample
     * carries no positions (`trackBounds()` is null).
     */
    private static function geoArtifact(Activity $activity): string
    {
        $bounds = FitReader::trackBounds($activity);
        if ($bounds === null) {
            return "No GPS data — no bounding box or polyline.\n";
        }

        $center = $bounds->center();
        $out = [];
        $out[] = 'Bounding box (all sessions):';
        $out[] = sprintf('  min     %.6f, %.6f', $bounds->minLat, $bounds->minLng);
        $out[] = sprintf('  max     %.6f, %.6f', $bounds->maxLat, $bounds->maxLng);
        $out[] = sprintf('  center  %.6f, %.6f', $center->lat, $center->lng);
        $out[] = '';

        foreach ($activity->sessions as $i => $session) {
            $full = FitReader::encodedPolyline($session);
            $thin = FitReader::encodedPolyline($session, 5, 1e-4);
            $points = 0;
            foreach ($session->records as $r) {
                if ($r->position() !== null) {
                    $points++;
                }
            }
            $out[] = sprintf('Session %d:', $i + 1);
            $out[] = sprintf('  GPS points: %d', $points);
            $out[] = sprintf('  encoded polyline (precision 5, %d chars):', strlen($full));
            $out[] = '    ' . $full;
            $out[] = sprintf('  simplified (~11 m, %d chars):', strlen($thin));
            $out[] = '    ' . $thin;
            $out[] = '';
        }

        return implode("\n", $out);
    }

    private function writeReport(Activity $activity, string $sourcePath, string $reportPath, ?string $pngPath = null): void
    {
        $md = [];
        $md[] = '# ' . basename($sourcePath);
        $md[] = '';
        $md[] = '_Generated by `tests/Integration/SampleFilesTest.php` on ' . date('Y-m-d H:i:s T') . '._';
        $md[] = '';

        $md[] = '## File';
        $md[] = '';
        $md[] = sprintf('- **Path:** `%s`', $sourcePath);
        $md[] = sprintf('- **Size:** %d bytes', filesize($sourcePath));
        $md[] = sprintf('- **Manufacturer:** %s', $activity->manufacturer() ?? '_unknown_');
        $md[] = sprintf('- **Time created:** %s', $activity->timeCreated()?->format('c') ?? '_unknown_');
        $md[] = sprintf('- **Sessions:** %d', $activity->numSessions());
        $md[] = sprintf('- **Device-info messages:** %d', count($activity->deviceInfos));
        $md[] = sprintf('- **Event messages:** %d', count($activity->events));
        if ($activity->totalTimerTime() !== null) {
            $md[] = sprintf('- **Activity total timer time:** %.0f s', $activity->totalTimerTime());
        }
        $md[] = '';

        foreach ($activity->sessions as $i => $session) {
            $md[] = sprintf('## Session %d', $i + 1);
            $md[] = '';
            $md = array_merge($md, $this->sessionTable($session));
            $md[] = '';
            $md = array_merge($md, $this->recordSummary($session));
            $md[] = '';
        }

        $md = array_merge($md, self::inlineSection('File ID', [$activity->fileId]));
        if ($activity->fileCreator !== null) {
            $md = array_merge($md, self::inlineSection('File Creator', [$activity->fileCreator]));
        }
        if ($activity->summary !== null) {
            $md = array_merge($md, self::inlineSection('Activity Summary', [$activity->summary]));
        }
        $md = array_merge(
            $md,
            self::inlineSection(
                'Sessions',
                array_map(static fn(Session $s) => $s->summary, $activity->sessions),
            ),
        );

        $lapRows = [];
        foreach ($activity->sessions as $i => $session) {
            foreach ($session->laps as $lap) {
                $lapRows[] = ['session_index' => $i] + $lap->summary;
            }
        }
        $md = array_merge($md, self::inlineSection('Laps', $lapRows));
        $md = array_merge($md, self::inlineSection('Device Info', $activity->deviceInfos));
        $md = array_merge($md, self::inlineSection('Events', $activity->events));

        $md[] = '## Outputs';
        $md[] = '';
        $base = pathinfo($sourcePath, PATHINFO_FILENAME);
        if ($pngPath !== null) {
            $pngName = basename($pngPath);
            $md[] = sprintf('![Map](%s)', $pngName);
            $md[] = '';
            $md[] = sprintf('- [Map PNG](%s)', $pngName);
        }
        $md[] = sprintf('- [CSV track (raw)](%s-raw.csv)', $base);
        $md[] = sprintf('- [CSV track (normalized)](%s-normalized.csv)', $base);
        $md[] = sprintf('- [GPX track (raw)](%s-raw.gpx)', $base);
        $md[] = sprintf('- [GPX track (normalized)](%s-normalized.gpx)', $base);
        $md[] = sprintf('- [TCX (raw)](%s-raw.tcx)', $base);
        $md[] = sprintf('- [TCX (normalized)](%s-normalized.tcx)', $base);
        $md[] = sprintf('- [KML (raw)](%s-raw.kml)', $base);
        $md[] = sprintf('- [KML (normalized)](%s-normalized.kml)', $base);
        $md[] = sprintf('- [GeoJSON (raw)](%s-raw.geojson)', $base);
        $md[] = sprintf('- [GeoJSON (normalized)](%s-normalized.geojson)', $base);
        $md[] = sprintf('- [Track geometry (bounds + polyline)](%s-geo.txt)', $base);
        $md[] = '';

        file_put_contents($reportPath, implode("\n", $md));
    }

    /**
     * Render `$rows` as a `## $title` heading followed by a Markdown table
     * (reusing CsvWriter's column-ordering + cell-formatting), with cells
     * scrubbed of control characters and Markdown-escaped.
     *
     * @param iterable<array<string|int, mixed>> $rows
     * @return string[]
     */
    private static function inlineSection(string $title, iterable $rows): array
    {
        $matrix = CsvWriter::buildMatrix($rows);
        $out = ['## ' . $title, ''];
        if ($matrix['cols'] === [] || $matrix['rows'] === []) {
            $out[] = '_(no data)_';
            $out[] = '';
            return $out;
        }
        $out[] = '| ' . implode(' | ', array_map(self::mdCell(...), $matrix['cols'])) . ' |';
        $out[] = '|' . str_repeat(' --- |', count($matrix['cols']));
        foreach ($matrix['rows'] as $row) {
            $out[] = '| ' . implode(' | ', array_map(self::mdCell(...), $row)) . ' |';
        }
        $out[] = '';
        return $out;
    }

    /**
     * Make a string safe to drop into a Markdown table cell: turn newlines
     * into `<br>`, drop C0 control characters and DEL, then escape pipes.
     */
    private static function mdCell(string $v): string
    {
        $v = str_replace(["\r\n", "\r", "\n"], '<br>', $v);
        $v = preg_replace('/[\x00-\x1F\x7F]/', '', $v) ?? '';
        return str_replace('|', '\\|', $v);
    }

    /** @return string[] */
    private function sessionTable(Session $s): array
    {
        $rows = [
            ['Sport',                  $s->sport() ?? '—'],
            ['Sub-sport',              $s->subSport() ?? '—'],
            ['Start time',             $s->startTime()?->format('c') ?? '—'],
            ['Total distance (m)',     self::fmt($s->totalDistance())],
            ['Total timer (s)',        self::fmt($s->totalTimerTime())],
            ['Total elapsed (s)',      self::fmt($s->totalElapsedTime())],
            ['Avg heart rate (bpm)',   self::fmt($s->avgHeartRate())],
            ['Max heart rate (bpm)',   self::fmt($s->maxHeartRate())],
            ['Avg cadence (rpm)',      self::fmt($s->avgCadence())],
            ['Max cadence (rpm)',      self::fmt($s->maxCadence())],
            ['Avg power (W)',          self::fmt($s->avgPower())],
            ['Max power (W)',          self::fmt($s->maxPower())],
            ['Total calories',         self::fmt($s->totalCalories())],
            ['Total ascent (m)',       self::fmt($s->totalAscent())],
            ['Total descent (m)',      self::fmt($s->totalDescent())],
            ['Laps',                   (string) count($s->laps)],
            ['Records (raw)',          (string) count($s->records)],
            ['Records (normalized)',   (string) count($s->recordsNormalized())],
        ];
        $out = ['| Field | Value |', '|-------|-------|'];
        foreach ($rows as [$k, $v]) {
            $out[] = "| {$k} | {$v} |";
        }
        return $out;
    }

    /** @return string[] */
    private function recordSummary(Session $s): array
    {
        if ($s->records === []) {
            return ['_No records in this session._'];
        }
        $first = $s->records[0];
        $last  = $s->records[count($s->records) - 1];
        $rawCounts  = self::countPopulated($s->records);
        $normalized = $s->recordsNormalized();
        $normCounts = self::countPopulated($normalized);
        $firstPos = null;
        foreach ($s->records as $r) {
            $p = $r->position();
            if ($p !== null) {
                $firstPos = $p;
                break;
            }
        }
        $out = ['### Records', ''];
        $out[] = sprintf('- **First record:** %s', $first->timestamp()?->format('c') ?? '—');
        $out[] = sprintf('- **Last record:**  %s', $last->timestamp()?->format('c') ?? '—');
        $out[] = sprintf('- **Raw records:** %d', count($s->records));
        $out[] = sprintf('- **With GPS:** %d', $rawCounts['gps']);
        $out[] = sprintf('- **With heart rate:** %d', $rawCounts['hr']);
        $out[] = sprintf('- **With cadence:** %d', $rawCounts['cad']);
        $out[] = sprintf('- **With power:** %d', $rawCounts['power']);
        if ($firstPos !== null) {
            $out[] = sprintf('- **First GPS fix:** %.6f, %.6f', $firstPos->lat, $firstPos->lng);
        }
        $out[] = '';
        $out[] = sprintf('After timestamp normalization (%d records):', count($normalized));
        $out[] = '';
        $out[] = sprintf('- **With GPS:** %d', $normCounts['gps']);
        $out[] = sprintf('- **With heart rate:** %d', $normCounts['hr']);
        $out[] = sprintf('- **With cadence:** %d', $normCounts['cad']);
        $out[] = sprintf('- **With power:** %d', $normCounts['power']);
        return $out;
    }

    /**
     * @param \Emontis\FitReader\Activity\Record[] $records
     * @return array{gps:int,hr:int,cad:int,power:int}
     */
    private static function countPopulated(array $records): array
    {
        $gps = $hr = $cad = $power = 0;
        foreach ($records as $r) {
            if ($r->position() !== null)  $gps++;
            if ($r->heartRate() !== null) $hr++;
            if ($r->cadence() !== null)   $cad++;
            if ($r->power() !== null)     $power++;
        }
        return ['gps' => $gps, 'hr' => $hr, 'cad' => $cad, 'power' => $power];
    }

    private static function fmt(mixed $v): string
    {
        if ($v === null)   return '—';
        if (is_int($v))    return (string) $v;
        if (is_float($v))  return sprintf('%.2f', $v);
        return (string) $v;
    }
}
