<?php

declare(strict_types=1);

namespace Emontis\FitReader\Activity;

use Emontis\FitReader\Value\GeoPoint;

/**
 * Strava (and a few other writers) split each logical 1 Hz sample into
 * several `record` messages at the same timestamp, each carrying a disjoint
 * subset of fields. This class collapses such a stream onto a continuous
 * step-second timeline (one record per step from min to max timestamp,
 * including any gaps in the raw data) and forward-fills missing fields
 * from the last known sample, so downstream consumers see one
 * fully-populated row per second.
 *
 * Configuration knobs are all constructor-injected with sensible defaults:
 *
 * - $stepSeconds: timeline cadence. Default 1.
 * - $zeroIsInvalid: fields where a value at or near 0 (|v| <= 0.05, so exact
 *   zeros and noisy near-zero float readings both count) means "no signal":
 *   skipped, then the previous valid value is carried. Default ['heart_rate'].
 * - $include: whitelist of field names to retain from the raw data. Null
 *   or [] means "all". Default null.
 * - $exclude: blacklist of field names to drop. Applied after $include.
 *   Default [].
 * - $noFill: fields that must NOT be forward/back-filled. Set on buckets
 *   that had a sample, null elsewhere. Useful for instantaneous metrics
 *   like speed/cadence/power where carrying forward a stale value would
 *   misrepresent reality. Default DEFAULT_NO_FILL.
 * - $derive: per-field derivers applied to each output record after fill, in
 *   declaration order. Each entry is either a deriver
 *   `fn(?Record $prev, Record $curr): mixed`, or a {@see DeriverFactory}
 *   (built via {@see perRun()}) that yields a fresh deriver at the start of
 *   every normalize() run — use the factory form for stateful derivers (the
 *   GPS speed/pace ones) when one normalizer instance is reused across several
 *   runs/sessions, so rolling state never leaks between them. The returned
 *   value replaces (or sets) that field on the record. Derivers bypass
 *   $include / $exclude — they always run and always write. Default [].
 */
final class ContinuousTimelineNormalizer
{
    /** Field names where a value at/near 0 means "no signal", not a real measurement. */
    public const DEFAULT_ZERO_IS_INVALID = ['heart_rate'];

    /**
     * Magnitude at or below which a `zeroIsInvalid` field counts as "no signal".
     * Catches exact 0 and float readings that are only nominally non-zero
     * (e.g. a 0.03 heart rate) that some writers emit in place of a clean 0.
     */
    private const ZERO_EPSILON = 0.05;

    /**
     * Instantaneous-metric fields whose stale values shouldn't be carried
     * forward when no fresh sample is available.
     */
    public const DEFAULT_NO_FILL = [];

    /** @var list<string> */
    private array $zeroIsInvalid;
    /** @var list<string>|null */
    private ?array $include;
    /** @var list<string> */
    private array $exclude;
    /** @var list<string> */
    private array $noFill;
    /** @var array<string, (callable(?Record, Record): mixed)|DeriverFactory> */
    private array $derive;

    /**
     * @param list<string>                                   $zeroIsInvalid
     * @param list<string>|null                              $include
     * @param list<string>                                   $exclude
     * @param list<string>                                   $noFill
     * @param array<string, (callable(?Record, Record): mixed)|DeriverFactory> $derive
     */
    public function __construct(
        private int $stepSeconds = 1,
        array $zeroIsInvalid = self::DEFAULT_ZERO_IS_INVALID,
        ?array $include = null,
        array $exclude = [],
        array $noFill = self::DEFAULT_NO_FILL,
        array $derive = [],
    ) {
        if ($stepSeconds < 1) {
            throw new \InvalidArgumentException('stepSeconds must be >= 1');
        }
        $this->zeroIsInvalid = $zeroIsInvalid;
        $this->include       = ($include === null || $include === []) ? null : $include;
        $this->exclude       = $exclude;
        $this->noFill        = $noFill;
        $this->derive        = $derive;
    }

    /**
     * Wrap a deriver factory for use in the `derive` map. The normalizer calls
     * `$factory` once at the start of every {@see normalize()} run to build a
     * fresh deriver, so a stateful deriver (e.g. the GPS speed/pace ones)
     * never carries rolling state across runs when one normalizer instance is
     * applied to several record sets (e.g. one session at a time).
     *
     * @param callable(): (callable(?Record, Record): mixed) $factory
     */
    public static function perRun(callable $factory): DeriverFactory
    {
        return new DeriverFactory($factory);
    }

    /** Meters per mile, for pace conversions. */
    private const METERS_PER_MILE = 1609.344;

    /**
     * Built-in deriver: instantaneous speed in **meters per second**, derived
     * from the great-circle (haversine) distance between the previous and
     * current GPS samples divided by the elapsed time. Returns null until a
     * second GPS sample is seen.
     *
     * The deriver tracks the most recent record that carried a real GPS fix,
     * so it works correctly even when interleaved with non-GPS records (as
     * long as `position` / `position_lat` / `position_long` are kept out of
     * the normalizer's fill set — otherwise stale carried-forward positions
     * would yield zero deltas).
     *
     * If $smoothingSeconds > 0, the value is replaced with the arithmetic
     * mean over the trailing window (useful for taming jittery GPS speeds).
     *
     * @return callable(?Record, Record): ?float
     */
    public static function metersPerSecondFromGps(int $smoothingSeconds = 0): callable
    {
        return self::gpsSpeedDeriver(
            static fn (float $m, int $s): float => $m / $s,
            $smoothingSeconds,
        );
    }

    /**
     * Built-in deriver: speed in **kilometers per hour**, derived from GPS.
     * See {@see metersPerSecondFromGps()}.
     *
     * @return callable(?Record, Record): ?float
     */
    public static function kilometersPerHourFromGps(int $smoothingSeconds = 0): callable
    {
        return self::gpsSpeedDeriver(
            static fn (float $m, int $s): float => ($m / $s) * 3.6,
            $smoothingSeconds,
        );
    }

    /**
     * Built-in deriver: speed in **miles per hour**, derived from GPS.
     * See {@see metersPerSecondFromGps()}.
     *
     * @return callable(?Record, Record): ?float
     */
    public static function milesPerHourFromGps(int $smoothingSeconds = 0): callable
    {
        return self::gpsSpeedDeriver(
            static fn (float $m, int $s): float => ($m / $s) * (3600.0 / self::METERS_PER_MILE),
            $smoothingSeconds,
        );
    }

    /**
     * Built-in deriver: pace in **minutes per kilometer**, derived from GPS.
     * Returns null while stationary (zero meters covered) since pace is
     * undefined there. See {@see metersPerSecondFromGps()}.
     *
     * @return callable(?Record, Record): ?float
     */
    public static function minutesPerKilometerFromGps(int $smoothingSeconds = 0): callable
    {
        return self::gpsSpeedDeriver(
            static function (float $m, int $s): ?float {
                return $m > 0.0 ? ($s / $m) * (1000.0 / 60.0) : null;
            },
            $smoothingSeconds,
        );
    }

    /**
     * Built-in deriver: pace in **minutes per mile**, derived from GPS.
     * Returns null while stationary. See {@see metersPerSecondFromGps()}.
     *
     * @return callable(?Record, Record): ?float
     */
    public static function minutesPerMileFromGps(int $smoothingSeconds = 0): callable
    {
        return self::gpsSpeedDeriver(
            static function (float $m, int $s): ?float {
                return $m > 0.0 ? ($s / $m) * (self::METERS_PER_MILE / 60.0) : null;
            },
            $smoothingSeconds,
        );
    }

    /**
     * Shared core for the GPS-based derivers. Maintains two pieces of state
     * in the closure: the most recent record that had a real GPS fix (used
     * as the "previous point" for the next Δdistance), and a rolling
     * window of recent values for optional smoothing.
     *
     * @param callable(float $meters, int $seconds): ?float $convert
     * @return callable(?Record, Record): ?float
     */
    private static function gpsSpeedDeriver(callable $convert, int $smoothingSeconds): callable
    {
        $lastGps = null;
        $history = [];
        return function (?Record $_prev, Record $curr) use (&$lastGps, &$history, $convert, $smoothingSeconds): ?float {
            $p2 = $curr->position();
            $t2 = $curr->timestamp();

            $inst = null;
            if ($p2 !== null && $t2 !== null) {
                if ($lastGps !== null) {
                    /** @var Record $lastGps */
                    $p1 = $lastGps->position();
                    $t1 = $lastGps->timestamp();
                    if ($p1 !== null && $t1 !== null) {
                        $dt = $t2->getTimestamp() - $t1->getTimestamp();
                        if ($dt > 0) {
                            $meters = self::haversineMeters($p1->lat, $p1->lng, $p2->lat, $p2->lng);
                            $inst = $convert($meters, $dt);
                        }
                    }
                }
                $lastGps = $curr;
            }

            if ($smoothingSeconds <= 0) {
                return $inst !== null ? round($inst, 2) : null;
            }
            $now = $t2?->getTimestamp();
            if ($now === null) {
                return $inst !== null ? round($inst, 2) : null;
            }
            $history[] = ['ts' => $now, 'v' => $inst];
            $cutoff = $now - $smoothingSeconds;
            while ($history !== [] && $history[0]['ts'] < $cutoff) {
                array_shift($history);
            }
            $sum = 0.0;
            $n   = 0;
            foreach ($history as $e) {
                if ($e['v'] !== null) {
                    $sum += $e['v'];
                    $n++;
                }
            }
            return $n > 0 ? round($sum / $n, 2) : null;
        };
    }

    /** Great-circle distance in meters between two lat/lng pairs (haversine). */
    private static function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6_371_008.8; // IUGG mean Earth radius in meters
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLam = deg2rad($lng2 - $lng1);
        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLam / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @param Record[] $raw
     * @return Record[]
     */
    public function normalize(array $raw): array
    {
        $buckets = [];
        foreach ($raw as $r) {
            $ts = $r->timestamp();
            if ($ts === null) {
                continue;
            }
            $key = intdiv($ts->getTimestamp(), $this->stepSeconds);
            if (!isset($buckets[$key])) {
                $buckets[$key] = ['ts' => $ts, 'records' => []];
            } elseif ($ts < $buckets[$key]['ts']) {
                $buckets[$key]['ts'] = $ts;
            }
            $buckets[$key]['records'][] = $r;
        }
        if ($buckets === []) {
            return [];
        }

        // Continuous timeline: synthesize an empty bucket for every step
        // between min and max so the output has no gaps. Forward-fill picks
        // up filled fields automatically; no-fill fields stay null there.
        $minKey = min(array_keys($buckets));
        $maxKey = max(array_keys($buckets));
        for ($k = $minKey; $k <= $maxKey; $k++) {
            if (!isset($buckets[$k])) {
                $buckets[$k] = [
                    'ts'      => new \DateTimeImmutable('@' . ($k * $this->stepSeconds)),
                    'records' => [],
                ];
            }
        }
        ksort($buckets);
        $bucketKeys = array_keys($buckets);

        // Per bucket: last valid value per field name.
        $perBucketValues = [];
        $fieldNames = [];
        foreach ($bucketKeys as $key) {
            $vals = [];
            foreach ($buckets[$key]['records'] as $r) {
                foreach ($r->all() as $name => $value) {
                    if (!$this->isValid($name, $value)) {
                        continue;
                    }
                    $vals[$name] = $value;
                    $fieldNames[$name] = true;
                }
            }
            $perBucketValues[$key] = $vals;
        }

        // Apply include / exclude to the raw field set.
        $allowed = array_keys($fieldNames);
        if ($this->include !== null) {
            $allowed = array_values(array_intersect($allowed, $this->include));
        }
        if ($this->exclude !== []) {
            $allowed = array_values(array_diff($allowed, $this->exclude));
        }

        // Fill (or don't, for no-fill fields).
        $filled = array_fill_keys($bucketKeys, []);
        $noFill = array_flip($this->noFill);
        foreach ($allowed as $name) {
            if (isset($noFill[$name])) {
                foreach ($bucketKeys as $key) {
                    if (array_key_exists($name, $perBucketValues[$key])) {
                        $filled[$key][$name] = $perBucketValues[$key][$name];
                    }
                }
                continue;
            }

            $firstIdx = null;
            $last = null;
            $haveLast = false;
            foreach ($bucketKeys as $i => $key) {
                if (array_key_exists($name, $perBucketValues[$key])) {
                    $last = $perBucketValues[$key][$name];
                    $haveLast = true;
                    if ($firstIdx === null) {
                        $firstIdx = $i;
                    }
                }
                if ($haveLast) {
                    $filled[$key][$name] = $last;
                }
            }
            if ($firstIdx === null || $firstIdx === 0) {
                continue;
            }
            $firstValue = $perBucketValues[$bucketKeys[$firstIdx]][$name];
            for ($i = 0; $i < $firstIdx; $i++) {
                $filled[$bucketKeys[$i]][$name] = $firstValue;
            }
        }

        // Derive pass: run each deriver and collect values into a parallel
        // map. We still need to build an intermediate Record per bucket so
        // derivers receive the documented (?Record $prev, Record $curr)
        // contract; those intermediate Records are discarded after this loop.
        //
        // Materialize derivers once for THIS run: a DeriverFactory is built
        // fresh here so its closure state is scoped to this normalize() call
        // and never leaks across runs; a bare deriver is used as-is.
        $derivers = [];
        foreach ($this->derive as $field => $d) {
            $derivers[$field] = $d instanceof DeriverFactory ? $d->build() : $d;
        }

        $derivedPerBucket = array_fill_keys($bucketKeys, []);
        $prev = null;
        foreach ($bucketKeys as $key) {
            $fields = $filled[$key];
            $fields['timestamp'] = $buckets[$key]['ts'];
            self::collapsePosition($fields);
            $record = new Record($fields);
            foreach ($derivers as $field => $fn) {
                $value = $fn($prev, $record);
                $derivedPerBucket[$key][$field] = $value;
                $fields[$field] = $value;
                $record = new Record($fields);
            }
            $prev = $record;
        }

        // Fill derived fields with the same forward + leading-back-fill
        // semantics used for raw fields. $noFill is the opt-out.
        foreach (array_keys($this->derive) as $field) {
            if (isset($noFill[$field])) {
                continue;
            }
            $firstIdx = null;
            $last = null;
            $haveLast = false;
            foreach ($bucketKeys as $i => $key) {
                $v = $derivedPerBucket[$key][$field] ?? null;
                if ($v !== null) {
                    $last = $v;
                    $haveLast = true;
                    if ($firstIdx === null) {
                        $firstIdx = $i;
                    }
                }
                if ($haveLast) {
                    $derivedPerBucket[$key][$field] = $last;
                }
            }
            if ($firstIdx === null || $firstIdx === 0) {
                continue;
            }
            $firstValue = $derivedPerBucket[$bucketKeys[$firstIdx]][$field];
            for ($i = 0; $i < $firstIdx; $i++) {
                $derivedPerBucket[$bucketKeys[$i]][$field] = $firstValue;
            }
        }

        // Final build: merge raw filled + derived filled into one Record per
        // bucket. Derived values are written last so they take precedence over
        // any same-named raw field (matching the previous in-place semantics).
        // Skip the lat/long → position re-collapse if the caller explicitly
        // excluded `position`; the derive pass above already gets it via its
        // own intermediate collapsePosition() call so derivers still see it.
        $emitPosition = !in_array('position', $this->exclude, true);
        $records = [];
        foreach ($bucketKeys as $key) {
            $fields = $filled[$key];
            $fields['timestamp'] = $buckets[$key]['ts'];
            if ($emitPosition) {
                self::collapsePosition($fields);
            }
            foreach ($derivedPerBucket[$key] as $name => $value) {
                $fields[$name] = $value;
            }
            $records[] = new Record($fields);
        }
        return $records;
    }

    private function isValid(string|int $name, mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($name)
            && in_array($name, $this->zeroIsInvalid, true)
            && (is_int($value) || is_float($value))
            && abs((float) $value) <= self::ZERO_EPSILON
        ) {
            return false;
        }
        return true;
    }

    /**
     * Re-collapse position_lat / position_long into a `position` GeoPoint
     * when both scalars are present but `position` itself isn't, mirroring
     * {@see \Emontis\FitReader\Protocol\Decoder::collapsePosition()}.
     *
     * @param array<string|int, mixed> $fields
     */
    private static function collapsePosition(array &$fields): void
    {
        if (!isset($fields['position'])
            && isset($fields['position_lat'], $fields['position_long'])
            && is_float($fields['position_lat'])
            && is_float($fields['position_long'])
        ) {
            $fields['position'] = new GeoPoint($fields['position_lat'], $fields['position_long']);
        }
    }
}
