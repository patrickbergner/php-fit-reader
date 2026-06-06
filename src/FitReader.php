<?php

declare(strict_types=1);

namespace Emontis\FitReader;

use Emontis\FitReader\Activity\Activity;
use Emontis\FitReader\Activity\ActivityReader;
use Emontis\FitReader\Activity\ContinuousTimelineNormalizer;
use Emontis\FitReader\Activity\DeriverFactory;
use Emontis\FitReader\Activity\Lap;
use Emontis\FitReader\Activity\Record;
use Emontis\FitReader\Activity\Session;
use Emontis\FitReader\Export\CsvWriter;
use Emontis\FitReader\Export\GeoJsonWriter;
use Emontis\FitReader\Export\GpxWriter;
use Emontis\FitReader\Export\KmlWriter;
use Emontis\FitReader\Export\MapboxRenderer;
use Emontis\FitReader\Export\TcxWriter;
use Emontis\FitReader\Geo\BoundingBox;
use Emontis\FitReader\Geo\DouglasPeucker;
use Emontis\FitReader\Geo\EncodedPolyline;
use Emontis\FitReader\Geo\TrackPoints;
use Emontis\FitReader\Io\BinaryStream;
use Emontis\FitReader\Message\Message;
use Emontis\FitReader\Protocol\Decoder;

/**
 * Public entry point.
 *
 * Reading:
 *   - FitReader::messages($path)  → \Generator<Message>  (streaming, low memory)
 *   - FitReader::activity($path)  → Activity             (eager, typed facade)
 *
 * The GPX and CSV exporters take a `$normalize` option (default false):
 *   - false ⇒ raw records — one trackpoint / CSV row per `record` message;
 *   - true  ⇒ default continuous-timeline normalization (1-second timeline,
 *             forward-filled), same as {@see Session::recordsNormalized()};
 *   - array ⇒ normalization with the array spread as named arguments into
 *             {@see ContinuousTimelineNormalizer} (`stepSeconds`,
 *             `zeroIsInvalid`, `include`, `exclude`, `noFill`, `derive`). No
 *             derived fields are added unless the caller puts them in
 *             `derive` — see {@see perRun()} for stateful derivers across
 *             multi-session files.
 *
 * GPX export (GPX 1.1 with Garmin TrackPointExtension v1 — atemp, depth,
 * hr, cad — plus a bare <power> sibling extension; derived fields like
 * speed/pace appear only when the caller's normalizer adds them):
 *   - FitReader::fitToGpx($fitPath, $gpxPath)        write file, returns Activity
 *   - FitReader::fitToGpxString($fitPath)            returns the XML string
 *   - FitReader::activityToGpx($activity, $gpxPath)  write file
 *   - FitReader::activityToGpxString($activity)      returns the XML string
 *
 * TCX export (Garmin TrainingCenterDatabase v2 — one <Activity> per session,
 * one <Lap> per lap with summary aggregates, native HR/Cadence/Distance/
 * Altitude trackpoints, Speed/Watts in the ActivityExtension v2 namespace;
 * $normalize is applied per lap):
 *   - FitReader::fitToTcx($fitPath, $tcxPath)        write file, returns Activity
 *   - FitReader::fitToTcxString($fitPath)            returns the XML string
 *   - FitReader::activityToTcx($activity, $tcxPath)  write file
 *   - FitReader::activityToTcxString($activity)      returns the XML string
 *
 * KML export (Google KML 2.2 — a time-animatable <gx:Track> per session, or a
 * plain <LineString> when records lack timestamps; HR/cadence as gx:Track
 * arrays. Visualization-first — for Google Earth / GIS):
 *   - FitReader::fitToKml($fitPath, $kmlPath)        write file, returns Activity
 *   - FitReader::fitToKmlString($fitPath)            returns the XML string
 *   - FitReader::activityToKml($activity, $kmlPath)  write file
 *   - FitReader::activityToKmlString($activity)      returns the XML string
 *
 * GeoJSON export (RFC 7946 FeatureCollection — one <LineString> per session in
 * [lng, lat] order, for web maps like Leaflet/MapLibre/OpenLayers; needs no
 * xmlwriter):
 *   - FitReader::fitToGeoJson($fitPath, $geoJsonPath)    write file, returns Activity
 *   - FitReader::fitToGeoJsonString($fitPath)            returns the JSON string
 *   - FitReader::activityToGeoJson($activity, $path)     write file
 *   - FitReader::activityToGeoJsonString($activity)      returns the JSON string
 *
 * CSV dump — a single file written to the exact `$path` the caller names (no
 * suffixing; the caller owns the filename). The small per-message tables are
 * intended to be inlined elsewhere via {@see CsvWriter::buildMatrix()}:
 *   - FitReader::fitToCsv($fitPath, $path)           write file, returns Activity
 *   - FitReader::activityToCsv($activity, $path)     write file
 *   - FitReader::fitToCsvString($fitPath)            returns the CSV string
 *   - FitReader::activityToCsvString($activity)      returns the CSV string
 *
 * Mapbox PNG (Static Images API; returns bool — true on success, false on a
 * soft failure such as no GPS, a network/HTTP error, or a track too long to
 * fit Mapbox's URL budget). The access token is required:
 *   - FitReader::fitToMapPng($fitPath, $pngPath, $token)        write file
 *   - FitReader::activityToMapPng($activity, $pngPath, $token)  write file
 *
 * Geo toolkit — track-visualization primitives for feeding any map provider:
 *   - FitReader::trackBounds($activityOrSession)   → ?BoundingBox (center/fitBounds)
 *   - FitReader::encodedPolyline($session)         → Google/Mapbox polyline string
 *   (and, directly: {@see EncodedPolyline}, {@see DouglasPeucker}, {@see TrackPoints})
 *
 * Normalization helpers (so callers only import FitReader):
 *   - FitReader::normalizer(...)    build a ContinuousTimelineNormalizer
 *   - FitReader::perRun($factory)   wrap a stateful deriver (rebuilt per run)
 *
 * Built-in record derivers for {@see ContinuousTimelineNormalizer} — pass
 * straight into the `derive:` constructor argument. Each takes an optional
 * smoothing window in seconds (0 = no smoothing). Defined here so consumers
 * don't need to import ContinuousTimelineNormalizer directly:
 *   - FitReader::metersPerSecondFromGps($smoothingSeconds)
 *   - FitReader::kilometersPerHourFromGps($smoothingSeconds)
 *   - FitReader::milesPerHourFromGps($smoothingSeconds)
 *   - FitReader::minutesPerKilometerFromGps($smoothingSeconds)
 *   - FitReader::minutesPerMileFromGps($smoothingSeconds)
 *
 * @phpstan-type NormalizeOptions array{stepSeconds?: int, zeroIsInvalid?: list<string>, include?: list<string>|null, exclude?: list<string>, noFill?: list<string>, derive?: array<string, (callable(?Record, Record): mixed)|DeriverFactory>}
 */
final class FitReader
{
    /**
     * Stream messages from a FIT file. Caller may stop iterating at any
     * point; the file is closed when the generator is destroyed.
     *
     * @return \Generator<int, Message>
     */
    public static function messages(string $path, bool $verifyCrc = true): \Generator
    {
        $stream  = BinaryStream::fromPath($path);
        $decoder = new Decoder($stream, verifyCrc: $verifyCrc);
        try {
            foreach ($decoder->messages() as $m) {
                yield $m;
            }
        } finally {
            $stream->close();
        }
    }

    public static function activity(string $path, bool $verifyCrc = true): Activity
    {
        return ActivityReader::read($path, verifyCrc: $verifyCrc);
    }

    /**
     * Decode a FIT activity file and write the GPS track as a GPX 1.1 file.
     * Returns the decoded Activity so callers can inspect the data too.
     * @param bool|NormalizeOptions $normalize
     */
    public static function fitToGpx(
        string $fitPath,
        string $gpxPath,
        ?string $name = null,
        bool $verifyCrc = true,
        bool|array $normalize = false,
    ): Activity {
        $activity = self::activity($fitPath, verifyCrc: $verifyCrc);
        new GpxWriter()->writeFile($activity, $gpxPath, $name, self::recordResolver($normalize));
        return $activity;
    }

    /**
     * Decode a FIT activity file and return the GPX 1.1 document as a string.
     * @param bool|NormalizeOptions $normalize
     */
    public static function fitToGpxString(
        string $fitPath,
        ?string $name = null,
        bool $verifyCrc = true,
        bool|array $normalize = false,
    ): string {
        return new GpxWriter()->toString(
            self::activity($fitPath, verifyCrc: $verifyCrc),
            $name,
            self::recordResolver($normalize),
        );
    }

    /**
     * Write the GPS track of an already-decoded Activity as a GPX 1.1 file.
     * @param bool|NormalizeOptions $normalize
     */
    public static function activityToGpx(
        Activity $activity,
        string $gpxPath,
        ?string $name = null,
        bool|array $normalize = false,
    ): void {
        new GpxWriter()->writeFile($activity, $gpxPath, $name, self::recordResolver($normalize));
    }

    /**
     * Render the GPS track of an already-decoded Activity as a GPX 1.1 string.
     * @param bool|NormalizeOptions $normalize
     */
    public static function activityToGpxString(
        Activity $activity,
        ?string $name = null,
        bool|array $normalize = false,
    ): string {
        return new GpxWriter()->toString($activity, $name, self::recordResolver($normalize));
    }

    /**
     * Decode a FIT activity file and write it as a Garmin TCX v2 file. One
     * <Activity> per session, one <Lap> per lap, with native HR/cadence/
     * distance/altitude and Speed/Watts in the ActivityExtension namespace.
     * Returns the decoded Activity so callers can inspect the data too.
     * @param bool|NormalizeOptions $normalize
     */
    public static function fitToTcx(
        string $fitPath,
        string $tcxPath,
        bool $verifyCrc = true,
        bool|array $normalize = false,
    ): Activity {
        $activity = self::activity($fitPath, verifyCrc: $verifyCrc);
        new TcxWriter()->writeFile($activity, $tcxPath, self::lapRecordResolver($normalize));
        return $activity;
    }

    /**
     * Decode a FIT activity file and return the TCX v2 document as a string.
     * @param bool|NormalizeOptions $normalize
     */
    public static function fitToTcxString(
        string $fitPath,
        bool $verifyCrc = true,
        bool|array $normalize = false,
    ): string {
        return new TcxWriter()->toString(
            self::activity($fitPath, verifyCrc: $verifyCrc),
            self::lapRecordResolver($normalize),
        );
    }

    /**
     * Write an already-decoded Activity as a Garmin TCX v2 file.
     * @param bool|NormalizeOptions $normalize
     */
    public static function activityToTcx(
        Activity $activity,
        string $tcxPath,
        bool|array $normalize = false,
    ): void {
        new TcxWriter()->writeFile($activity, $tcxPath, self::lapRecordResolver($normalize));
    }

    /**
     * Render an already-decoded Activity as a TCX v2 string.
     * @param bool|NormalizeOptions $normalize
     */
    public static function activityToTcxString(
        Activity $activity,
        bool|array $normalize = false,
    ): string {
        return new TcxWriter()->toString($activity, self::lapRecordResolver($normalize));
    }

    /**
     * Decode a FIT activity file and write its GPS track(s) as a Google KML
     * 2.2 file — a time-animatable <gx:Track> per session (or a plain
     * <LineString> when records lack timestamps), styled for Google Earth.
     * Returns the decoded Activity so callers can inspect the data too.
     * @param bool|NormalizeOptions $normalize
     */
    public static function fitToKml(
        string $fitPath,
        string $kmlPath,
        ?string $name = null,
        bool $verifyCrc = true,
        bool|array $normalize = false,
    ): Activity {
        $activity = self::activity($fitPath, verifyCrc: $verifyCrc);
        new KmlWriter()->writeFile($activity, $kmlPath, $name, self::recordResolver($normalize));
        return $activity;
    }

    /**
     * Decode a FIT activity file and return the KML 2.2 document as a string.
     * @param bool|NormalizeOptions $normalize
     */
    public static function fitToKmlString(
        string $fitPath,
        ?string $name = null,
        bool $verifyCrc = true,
        bool|array $normalize = false,
    ): string {
        return new KmlWriter()->toString(
            self::activity($fitPath, verifyCrc: $verifyCrc),
            $name,
            self::recordResolver($normalize),
        );
    }

    /**
     * Write the GPS track(s) of an already-decoded Activity as a KML 2.2 file.
     * @param bool|NormalizeOptions $normalize
     */
    public static function activityToKml(
        Activity $activity,
        string $kmlPath,
        ?string $name = null,
        bool|array $normalize = false,
    ): void {
        new KmlWriter()->writeFile($activity, $kmlPath, $name, self::recordResolver($normalize));
    }

    /**
     * Render the GPS track(s) of an already-decoded Activity as a KML 2.2 string.
     * @param bool|NormalizeOptions $normalize
     */
    public static function activityToKmlString(
        Activity $activity,
        ?string $name = null,
        bool|array $normalize = false,
    ): string {
        return new KmlWriter()->toString($activity, $name, self::recordResolver($normalize));
    }

    /**
     * Decode a FIT activity file and write its GPS track(s) as a GeoJSON
     * (RFC 7946) FeatureCollection — one <LineString> per session, ready for
     * web maps (Leaflet/MapLibre/OpenLayers). Returns the decoded Activity so
     * callers can inspect the data too.
     * @param bool|NormalizeOptions $normalize
     */
    public static function fitToGeoJson(
        string $fitPath,
        string $geoJsonPath,
        bool $verifyCrc = true,
        bool|array $normalize = false,
    ): Activity {
        $activity = self::activity($fitPath, verifyCrc: $verifyCrc);
        new GeoJsonWriter()->writeFile($activity, $geoJsonPath, self::recordResolver($normalize));
        return $activity;
    }

    /**
     * Decode a FIT activity file and return the GeoJSON document as a string.
     * @param bool|NormalizeOptions $normalize
     */
    public static function fitToGeoJsonString(
        string $fitPath,
        bool $verifyCrc = true,
        bool|array $normalize = false,
    ): string {
        return new GeoJsonWriter()->toString(
            self::activity($fitPath, verifyCrc: $verifyCrc),
            self::recordResolver($normalize),
        );
    }

    /**
     * Write the GPS track(s) of an already-decoded Activity as a GeoJSON file.
     * @param bool|NormalizeOptions $normalize
     */
    public static function activityToGeoJson(
        Activity $activity,
        string $geoJsonPath,
        bool|array $normalize = false,
    ): void {
        new GeoJsonWriter()->writeFile($activity, $geoJsonPath, self::recordResolver($normalize));
    }

    /**
     * Render the GPS track(s) of an already-decoded Activity as a GeoJSON string.
     * @param bool|NormalizeOptions $normalize
     */
    public static function activityToGeoJsonString(
        Activity $activity,
        bool|array $normalize = false,
    ): string {
        return new GeoJsonWriter()->toString($activity, self::recordResolver($normalize));
    }

    /**
     * Pick the per-session record resolver for export. `$normalize`:
     *   - false ⇒ the session's raw records;
     *   - true  ⇒ default continuous-timeline normalization;
     *   - array ⇒ normalization with the array spread as named args into
     *             {@see ContinuousTimelineNormalizer}.
     *
     * A fresh normalizer is built per session, so stateful derivers wrapped
     * with {@see ContinuousTimelineNormalizer::perRun()} stay isolated per
     * session.
     *
     * @param bool|NormalizeOptions $normalize
     * @return callable(Session): list<\Emontis\FitReader\Activity\Record>
     */
    private static function recordResolver(bool|array $normalize): callable
    {
        if ($normalize === false) {
            return static fn (Session $s): array => $s->records;
        }
        $config = $normalize === true ? [] : $normalize;
        return static fn (Session $s): array => new ContinuousTimelineNormalizer(...$config)->normalize($s->records);
    }

    /**
     * Per-lap counterpart of {@see recordResolver()} for the TCX exporter,
     * which groups trackpoints under their lap. A fresh normalizer is built
     * per lap, so stateful {@see perRun()} derivers stay isolated per lap.
     *
     * @param bool|NormalizeOptions $normalize
     * @return callable(Lap): list<\Emontis\FitReader\Activity\Record>
     */
    private static function lapRecordResolver(bool|array $normalize): callable
    {
        if ($normalize === false) {
            return static fn (Lap $l): array => $l->records;
        }
        $config = $normalize === true ? [] : $normalize;
        return static fn (Lap $l): array => new ContinuousTimelineNormalizer(...$config)->normalize($l->records);
    }

    /**
     * Decode a FIT activity file and dump its records as a single CSV at the
     * exact `$path` given (no suffixing — the caller owns the filename). Raw
     * records by default; pass $normalize (`true`, or an options array) to
     * write a normalized continuous timeline instead. Returns the decoded
     * Activity so callers can inspect the data too.
     *
     * @param bool|NormalizeOptions $normalize
     * @param array<string, string>     $csv CSV dialect overrides (separator/enclosure/escape/eol), spread into {@see CsvWriter}
     */
    public static function fitToCsv(
        string $fitPath,
        string $path,
        bool|array $normalize = false,
        bool $verifyCrc = true,
        array $csv = [],
    ): Activity {
        $activity = self::activity($fitPath, verifyCrc: $verifyCrc);
        self::activityToCsv($activity, $path, $normalize, $csv);
        return $activity;
    }

    /**
     * Decode a FIT activity file and return its records as a CSV string instead
     * of writing a file — the string counterpart of {@see fitToCsv()}, same
     * rows/columns as {@see activityToCsvString()}.
     *
     * @param bool|NormalizeOptions $normalize
     * @param array<string, string>     $csv CSV dialect overrides (separator/enclosure/escape/eol), spread into {@see CsvWriter}
     */
    public static function fitToCsvString(
        string $fitPath,
        bool|array $normalize = false,
        bool $verifyCrc = true,
        array $csv = [],
    ): string {
        return self::activityToCsvString(self::activity($fitPath, verifyCrc: $verifyCrc), $normalize, $csv);
    }

    /**
     * Dump the records of an already-decoded Activity as a single CSV at the
     * exact `$path` given. Each row carries a leading `session_index` column
     * so multi-session activities stay distinguishable. Raw records by
     * default; pass $normalize (`true`, or an options array) for a normalized
     * continuous timeline.
     *
     * @param bool|NormalizeOptions $normalize
     * @param array<string, string>     $csv CSV dialect overrides (separator/enclosure/escape/eol), spread into {@see CsvWriter}
     */
    public static function activityToCsv(
        Activity $activity,
        string $path,
        bool|array $normalize = false,
        array $csv = [],
    ): void {
        new CsvWriter(...$csv)->writeRows($path, self::csvRows($activity, $normalize));
    }

    /**
     * Render the records of an already-decoded Activity as a CSV string instead
     * of writing a file. Same rows/columns as {@see activityToCsv()}.
     *
     * @param bool|NormalizeOptions $normalize
     * @param array<string, string>     $csv CSV dialect overrides (separator/enclosure/escape/eol), spread into {@see CsvWriter}
     */
    public static function activityToCsvString(
        Activity $activity,
        bool|array $normalize = false,
        array $csv = [],
    ): string {
        return new CsvWriter(...$csv)->toString(self::csvRows($activity, $normalize));
    }

    /**
     * The CSV row set shared by the CSV exporters: each output record as a
     * field dict, with a leading `session_index` column so multi-session
     * activities stay distinguishable.
     *
     * @param bool|NormalizeOptions $normalize
     * @return list<array<string|int, mixed>>
     */
    private static function csvRows(Activity $activity, bool|array $normalize): array
    {
        $resolve = self::recordResolver($normalize);
        $rows = [];
        foreach ($activity->sessions as $i => $session) {
            foreach ($resolve($session) as $r) {
                $rows[] = ['session_index' => $i] + $r->all();
            }
        }
        return $rows;
    }

    /**
     * Render the GPS track(s) of an already-decoded Activity as a PNG via
     * the Mapbox Static Images API. The track is built from the raw records
     * (a map only needs `position`; the 1-second timeline would just add
     * redundant points for the line simplifier to discard). Returns true on
     * success, false if no PNG could be produced (no GPS in the activity,
     * network/HTTP error, track too long to fit Mapbox's URL budget even
     * after simplification). Network and HTTP failures emit an
     * `E_USER_WARNING` and return false; they do not throw. The token is
     * **required** — callers decide where it comes from (config file, env
     * var, secret manager). An empty token is a programmer error and throws
     * `\InvalidArgumentException`.
     */
    public static function activityToMapPng(
        Activity $activity,
        string $path,
        string $accessToken,
        string $styleId = 'mapbox/outdoors-v12',
    ): bool {
        return new MapboxRenderer($accessToken, $styleId)->writeFile(
            $activity,
            $path,
            static fn (Session $s): array => $s->records,
        );
    }

    /**
     * Decode a FIT activity file and render its GPS track(s) as a Mapbox PNG.
     * Mirrors {@see fitToGpx()} / {@see fitToCsv()}, but returns the renderer's
     * success flag (the meaningful result for a map) rather than the Activity —
     * see {@see activityToMapPng()} for the soft-fail contract.
     */
    public static function fitToMapPng(
        string $fitPath,
        string $path,
        string $accessToken,
        bool $verifyCrc = true,
        string $styleId = 'mapbox/outdoors-v12',
    ): bool {
        return self::activityToMapPng(
            self::activity($fitPath, verifyCrc: $verifyCrc),
            $path,
            $accessToken,
            $styleId,
        );
    }

    /**
     * Geographic bounding box of an Activity's (or single Session's) GPS track
     * — the framing primitive map providers want: a center/zoom for a static
     * image, or a `fitBounds` rectangle for a web map. Built from the raw GPS
     * fixes across every session. Returns null when there is no GPS data.
     *
     * @see BoundingBox::center()
     */
    public static function trackBounds(Activity|Session $source): ?BoundingBox
    {
        $records = $source instanceof Session
            ? $source->records
            : array_merge(...array_map(static fn (Session $s): array => $s->records, $source->sessions));

        return BoundingBox::fromPoints(TrackPoints::of($records));
    }

    /**
     * Encode a Session's GPS track as a Google "encoded polyline" — the compact
     * string both the Mapbox Static Images API (`path-…`) and the Google Maps
     * Static API (`path=enc:…`) accept as a route overlay. Returns '' for a
     * session with no GPS.
     *
     * `$precision` is the coordinate precision (5 for Mapbox/Google, 6 also
     * valid). `$simplifyToleranceDegrees` (> 0) first thins the track with
     * {@see DouglasPeucker} — useful to keep the resulting URL short.
     */
    public static function encodedPolyline(
        Session $session,
        int $precision = 5,
        float $simplifyToleranceDegrees = 0.0,
    ): string {
        $points = TrackPoints::of($session->records);
        if ($simplifyToleranceDegrees > 0.0) {
            $points = DouglasPeucker::simplify($points, $simplifyToleranceDegrees);
        }
        return EncodedPolyline::encode($points, $precision);
    }

    /**
     * Shorthand for `new ContinuousTimelineNormalizer(...)` so callers only
     * need to import {@see FitReader} when working with normalization. The
     * parameters mirror the constructor one-for-one; see
     * {@see ContinuousTimelineNormalizer} for the full semantics of each.
     *
     * @param list<string>      $zeroIsInvalid
     * @param list<string>|null $include
     * @param list<string>      $exclude
     * @param list<string>      $noFill
     * @param array<string, (callable(?\Emontis\FitReader\Activity\Record, \Emontis\FitReader\Activity\Record): mixed)|DeriverFactory> $derive
     */
    public static function normalizer(
        int $stepSeconds = 1,
        array $zeroIsInvalid = ContinuousTimelineNormalizer::DEFAULT_ZERO_IS_INVALID,
        ?array $include = null,
        array $exclude = [],
        array $noFill = ContinuousTimelineNormalizer::DEFAULT_NO_FILL,
        array $derive = [],
    ): ContinuousTimelineNormalizer {
        return new ContinuousTimelineNormalizer(
            stepSeconds:   $stepSeconds,
            zeroIsInvalid: $zeroIsInvalid,
            include:       $include,
            exclude:       $exclude,
            noFill:        $noFill,
            derive:        $derive,
        );
    }

    /**
     * Wrap a deriver factory so the normalizer rebuilds it fresh for each
     * session/run — see {@see ContinuousTimelineNormalizer::perRun()}. Use it
     * for stateful derivers (the GPS speed/pace ones) so their rolling state
     * never leaks across sessions in a multi-session file:
     *
     *     normalize: ['derive' => [
     *         'speed_kmh' => FitReader::perRun(fn () => FitReader::kilometersPerHourFromGps(10)),
     *     ]]
     *
     * @param callable(): (callable(?\Emontis\FitReader\Activity\Record, \Emontis\FitReader\Activity\Record): mixed) $factory
     */
    public static function perRun(callable $factory): DeriverFactory
    {
        return ContinuousTimelineNormalizer::perRun($factory);
    }

    /**
     * GPS-derived instantaneous speed in meters per second.
     * See {@see ContinuousTimelineNormalizer::metersPerSecondFromGps()}.
     *
     * @return callable(?\Emontis\FitReader\Activity\Record, \Emontis\FitReader\Activity\Record): ?float
     */
    public static function metersPerSecondFromGps(int $smoothingSeconds = 0): callable
    {
        return ContinuousTimelineNormalizer::metersPerSecondFromGps($smoothingSeconds);
    }

    /**
     * GPS-derived speed in kilometers per hour.
     * See {@see ContinuousTimelineNormalizer::kilometersPerHourFromGps()}.
     *
     * @return callable(?\Emontis\FitReader\Activity\Record, \Emontis\FitReader\Activity\Record): ?float
     */
    public static function kilometersPerHourFromGps(int $smoothingSeconds = 0): callable
    {
        return ContinuousTimelineNormalizer::kilometersPerHourFromGps($smoothingSeconds);
    }

    /**
     * GPS-derived speed in miles per hour.
     * See {@see ContinuousTimelineNormalizer::milesPerHourFromGps()}.
     *
     * @return callable(?\Emontis\FitReader\Activity\Record, \Emontis\FitReader\Activity\Record): ?float
     */
    public static function milesPerHourFromGps(int $smoothingSeconds = 0): callable
    {
        return ContinuousTimelineNormalizer::milesPerHourFromGps($smoothingSeconds);
    }

    /**
     * GPS-derived pace in minutes per kilometer. Null while stationary.
     * See {@see ContinuousTimelineNormalizer::minutesPerKilometerFromGps()}.
     *
     * @return callable(?\Emontis\FitReader\Activity\Record, \Emontis\FitReader\Activity\Record): ?float
     */
    public static function minutesPerKilometerFromGps(int $smoothingSeconds = 0): callable
    {
        return ContinuousTimelineNormalizer::minutesPerKilometerFromGps($smoothingSeconds);
    }

    /**
     * GPS-derived pace in minutes per mile. Null while stationary.
     * See {@see ContinuousTimelineNormalizer::minutesPerMileFromGps()}.
     *
     * @return callable(?\Emontis\FitReader\Activity\Record, \Emontis\FitReader\Activity\Record): ?float
     */
    public static function minutesPerMileFromGps(int $smoothingSeconds = 0): callable
    {
        return ContinuousTimelineNormalizer::minutesPerMileFromGps($smoothingSeconds);
    }
}
