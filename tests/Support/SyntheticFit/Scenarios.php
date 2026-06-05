<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Support\SyntheticFit;

use Emontis\FitReader\Protocol\BaseType;
use Emontis\FitReader\Value\FitTimestamp;

/**
 * Scenario library — one public static method per synthetic .fit file the
 * `bin/generate-default-samples` script writes into samples/default/. Each
 * scenario is deterministic (fixed start time, no randomness anywhere) so
 * the committed binaries diff cleanly when scenarios change.
 *
 * Helpers below carry the boilerplate so each scenario stays focused on its
 * distinctive shape (sport, sample cadence, sensor coverage, etc.).
 */
final class Scenarios
{
    /** Shared start time so every sample is comparable. */
    private const START = '2026-01-15 09:00:00 UTC';

    /** Berlin Brandenburg Gate-ish — origin for all GPS scenarios. */
    private const ORIGIN_LAT = 52.5170;
    private const ORIGIN_LNG = 13.3889;

    // -----------------------------------------------------------------
    // Running
    // -----------------------------------------------------------------

    public static function outdoorRunClean(): Writer
    {
        $w        = new Writer();
        $start    = self::start();
        $duration = 1800;       // 30 min
        $pace     = 3.0;        // m/s ~ 5:33 / km
        $totalKm  = $pace * $duration / 1000.0;

        self::header($w, $start);
        self::startEvent($w, $start);
        [$lat, $lng] = [self::ORIGIN_LAT, self::ORIGIN_LNG];
        $dist = 0.0;
        for ($t = 0; $t < $duration; $t++) {
            $w->add('record', [
                'timestamp'    => $start->modify("+{$t} sec"),
                'position_lat' => $lat,
                'position_long'=> $lng,
                'distance'     => $dist,
                'altitude'     => 35.0 + sin($t / 90.0) * 3.0,
                'heart_rate'   => self::hrRamp($t, $duration, 130, 168),
                'cadence'      => 175 + ($t % 4),
                'speed'        => $pace,
                'temperature'  => 12,
                'gps_accuracy' => 3,
            ]);
            [$lat, $lng] = self::step($lat, $lng, 90 + sin($t / 120.0) * 30, $pace);
            $dist += $pace;
        }
        $end = $start->modify("+{$duration} sec");
        self::stopEvent($w, $end);
        self::lap($w, $start, $duration, $pace * $duration, 'running',
            avgHr: 148, maxHr: 168, avgCad: 176, maxCad: 184,
            calories: 380, ascent: 12, descent: 12);
        self::session($w, $start, $duration, 'running', $pace * $duration,
            avgHr: 148, maxHr: 168, avgCad: 176, maxCad: 184,
            calories: 380, ascent: 12, descent: 12);
        self::activity($w, $end, 1, $duration);
        return $w;
    }

    public static function outdoorRunFragmented(): Writer
    {
        $w        = new Writer();
        $start    = self::start();
        $duration = 1800;
        $pace     = 3.0;

        self::header($w, $start, manufacturer: 'strava');
        self::startEvent($w, $start);
        [$lat, $lng] = [self::ORIGIN_LAT, self::ORIGIN_LNG];
        $dist = 0.0;
        for ($t = 0; $t < $duration; $t++) {
            $ts = $start->modify("+{$t} sec");
            // Strava-style: one field set per record-message at the same timestamp.
            $w->add('record', ['timestamp' => $ts, 'position_lat' => $lat, 'position_long' => $lng, 'gps_accuracy' => 4]);
            $w->add('record', ['timestamp' => $ts, 'heart_rate' => self::hrRamp($t, $duration, 130, 168)]);
            $w->add('record', ['timestamp' => $ts, 'distance' => $dist]);
            [$lat, $lng] = self::step($lat, $lng, 90, $pace);
            $dist += $pace;
        }
        $end = $start->modify("+{$duration} sec");
        self::stopEvent($w, $end);
        self::lap($w, $start, $duration, $pace * $duration, 'running');
        // No HR/cad aggregates on the session — forces the records fallback.
        self::session($w, $start, $duration, 'running', $pace * $duration);
        self::activity($w, $end, 1, $duration);
        return $w;
    }

    public static function treadmillRun(): Writer
    {
        $w        = new Writer();
        $start    = self::start();
        $duration = 1500; // 25 min
        $pace     = 3.3;  // m/s
        $dist     = 0.0;

        self::header($w, $start);
        self::startEvent($w, $start);
        for ($t = 0; $t < $duration; $t++) {
            $w->add('record', [
                'timestamp'  => $start->modify("+{$t} sec"),
                'distance'   => $dist,
                'speed'      => $pace,
                'heart_rate' => self::hrRamp($t, $duration, 125, 158),
                'cadence'    => 180,
            ]);
            $dist += $pace;
        }
        $end = $start->modify("+{$duration} sec");
        self::stopEvent($w, $end);
        self::lap($w, $start, $duration, $pace * $duration, 'running', subSport: 'treadmill',
            avgHr: 140, maxHr: 158, avgCad: 180, calories: 290);
        self::session($w, $start, $duration, 'running', $pace * $duration, subSport: 'treadmill',
            avgHr: 140, maxHr: 158, avgCad: 180, calories: 290);
        self::activity($w, $end, 1, $duration);
        return $w;
    }

    public static function trailRunElevation(): Writer
    {
        $w        = new Writer();
        $start    = self::start();
        $duration = 3600; // 1 h
        $pace     = 2.4;  // m/s (slower, hilly)
        [$lat, $lng] = [self::ORIGIN_LAT, self::ORIGIN_LNG];
        $dist  = 0.0;
        $ascent = 0; $descent = 0; $prevAlt = 100.0;
        self::header($w, $start);
        self::startEvent($w, $start);
        for ($t = 0; $t < $duration; $t++) {
            $alt = 100.0 + 300.0 * (1 - cos(2 * M_PI * $t / $duration)) / 2;
            $grade = ($alt - $prevAlt) * 100.0; // % per second-step ~= per meter
            if ($alt > $prevAlt) $ascent += (int) round($alt - $prevAlt);
            else                  $descent += (int) round($prevAlt - $alt);
            $w->add('record', [
                'timestamp'    => $start->modify("+{$t} sec"),
                'position_lat' => $lat,
                'position_long'=> $lng,
                'distance'     => $dist,
                'altitude'     => $alt,
                'grade'        => max(-25.0, min(25.0, $grade)),
                'heart_rate'   => self::hrRamp($t, $duration, 135, 175),
                'cadence'      => 160,
                'speed'        => $pace,
                'temperature'  => 8,
                'gps_accuracy' => 5,
            ]);
            [$lat, $lng] = self::step($lat, $lng, 45 + sin($t / 100.0) * 60, $pace);
            $dist += $pace;
            $prevAlt = $alt;
        }
        $end = $start->modify("+{$duration} sec");
        self::stopEvent($w, $end);
        self::lap($w, $start, $duration, $pace * $duration, 'running', subSport: 'trail',
            avgHr: 155, maxHr: 178, avgCad: 160, calories: 720, ascent: $ascent, descent: $descent);
        self::session($w, $start, $duration, 'running', $pace * $duration, subSport: 'trail',
            avgHr: 155, maxHr: 178, avgCad: 160, calories: 720, ascent: $ascent, descent: $descent);
        self::activity($w, $end, 1, $duration);
        return $w;
    }

    public static function intervalRunLaps(): Writer
    {
        $w     = new Writer();
        $start = self::start();
        self::header($w, $start);
        self::startEvent($w, $start);
        [$lat, $lng] = [self::ORIGIN_LAT, self::ORIGIN_LNG];
        $cursor = $start;
        $dist = 0.0;
        // 6 × (400 m fast at 4.5 m/s = 89 s, 200 m jog at 2.5 m/s = 80 s)
        for ($i = 0; $i < 6; $i++) {
            $lapStart = $cursor;
            $lapDist  = 0.0;
            foreach ([[89, 4.5, 178, 165], [80, 2.5, 165, 130]] as [$dur, $pace, $hrPeak, $hrFloor]) {
                for ($t = 0; $t < $dur; $t++) {
                    $w->add('record', [
                        'timestamp'    => $cursor,
                        'position_lat' => $lat,
                        'position_long'=> $lng,
                        'distance'     => $dist,
                        'altitude'     => 35.0,
                        'heart_rate'   => $hrFloor + (int) (($hrPeak - $hrFloor) * ($t / $dur)),
                        'cadence'      => $pace > 4 ? 195 : 170,
                        'speed'        => $pace,
                    ]);
                    [$lat, $lng] = self::step($lat, $lng, 0, $pace);
                    $dist     += $pace;
                    $lapDist  += $pace;
                    $cursor    = $cursor->modify('+1 sec');
                }
            }
            $lapDur = $cursor->getTimestamp() - $lapStart->getTimestamp();
            self::lap($w, $lapStart, $lapDur, $lapDist, 'running',
                avgHr: 160, maxHr: 180, avgCad: 182, calories: 60);
        }
        $end = $cursor;
        $totalDur = $end->getTimestamp() - $start->getTimestamp();
        self::stopEvent($w, $end);
        self::session($w, $start, $totalDur, 'running', $dist,
            numLaps: 6, avgHr: 160, maxHr: 182, avgCad: 182, calories: 360);
        self::activity($w, $end, 1, $totalDur);
        return $w;
    }

    /**
     * A realistic ~60 min / ~12 km fartlek run through Berlin's Großer
     * Tiergarten, starting and finishing at the Brandenburg Gate. A ~4 km loop
     * (scaled to length about the gate) is run three times; pace alternates
     * between fast and slow blocks. This is the rich fixture behind the runnable
     * examples in demos/, so it deliberately looks like a real multi-sensor
     * capture rather than a tidy 1 second stream:
     *
     *   - Three paired sensors (own device_info each): the watch GPS, a chest
     *     strap (heart_rate + respiration) and a running-dynamics footpod
     *     (cadence + fractional_cadence, power, vertical_oscillation,
     *     stance_time(_percent), vertical_ratio, step_length).
     *   - Variable message frequency / partial records: mostly merged 1 second, but
     *     one 5-minute block arrives Strava-fragmented — position, heart rate and
     *     footpod each in their own message at the same timestamp, so that stretch
     *     has position-only and heart-rate-only records — and another block has
     *     the footpod disconnected (records without cadence/dynamics). The
     *     position channel itself stays a dense 1 second so GPS-derived speed/pace
     *     stays sane.
     *   - Two brief GPS dropouts (canopy/underpass; position omitted, the watch
     *     keeps distance/speed) and a brief HR-strap reseat.
     *   - The three laps fan onto slightly different lines (they don't trace the
     *     same pixels), each fading back to the gate, with a gentle wander.
     *
     * Lap/session aggregates are computed from the ground-truth per-second state
     * (what the body did), independent of how the devices happened to record it.
     */
    public static function tiergartenRun(): Writer
    {
        $w     = new Writer();
        $start = self::start();

        // One loop through the park; first point == last point == the gate.
        $loop = [
            [52.5163, 13.3777], // Brandenburg Gate
            [52.5155, 13.3690], // Straße des 17. Juni, heading west
            [52.5147, 13.3585],
            [52.5145, 13.3505], // Großer Stern / Siegessäule
            [52.5160, 13.3520], // toward Schloss Bellevue
            [52.5150, 13.3460], // north-west paths
            [52.5118, 13.3485], // Neuer See
            [52.5102, 13.3560], // southern paths
            [52.5112, 13.3660], // toward Tiergartenstraße
            [52.5138, 13.3720],
            [52.5152, 13.3762],
            [52.5163, 13.3777], // back to the gate
        ];

        // Scale the loop about the gate so one lap is ~4 km (→ ~12 km total).
        // Uniform scaling in degree space about a fixed point scales every
        // segment's metric length by the same factor, so the lap comes out at
        // the target length and the gate (the fixed point) stays put.
        $gate = $loop[0];
        $factor = 4000.0 / self::polylineLength($loop);
        foreach ($loop as $i => [$la, $lo]) {
            $loop[$i] = [$gate[0] + ($la - $gate[0]) * $factor, $gate[1] + ($lo - $gate[1]) * $factor];
        }
        $gate = $loop[0];

        // Per-segment start point, bearing and cumulative length for one lap.
        $segs = [];
        $cum  = [0.0];
        for ($i = 0, $n = count($loop) - 1; $i < $n; $i++) {
            [$alat, $alng] = $loop[$i];
            [$blat, $blng] = $loop[$i + 1];
            $segs[] = [
                'lat' => $alat,
                'lng' => $alng,
                'brg' => self::bearingDeg($alat, $alng, $blat, $blng),
            ];
            $cum[] = $cum[$i] + self::distanceMeters($alat, $alng, $blat, $blng);
        }
        $loopLen  = $cum[count($cum) - 1];
        $loops    = 3;
        $totalLen = $loopLen * $loops;

        // Position at absolute distance $d. Each lap is fanned onto its own line
        // (so the three passes don't overlap) by a perpendicular offset that
        // tapers to zero at the gate, plus a gentle low-frequency wander so the
        // track isn't a dead-straight polyline. The offset is smooth on purpose:
        // high-frequency noise here would wreck GPS-derived speed/pace.
        $pointAt = static function (float $d) use ($segs, $cum, $loopLen, $loops): array {
            $loopIdx  = min($loops - 1, (int) floor($d / $loopLen));
            $loopDist = fmod($d, $loopLen);
            $i = 0;
            $n = count($segs);
            while ($i < $n - 1 && $loopDist >= $cum[$i + 1]) {
                $i++;
            }
            [$lat, $lng] = self::step($segs[$i]['lat'], $segs[$i]['lng'], $segs[$i]['brg'], $loopDist - $cum[$i]);
            $taper = sin(M_PI * $loopDist / $loopLen);            // 0 at the gate, 1 mid-lap
            $side  = ([0.0, 16.0, -14.0][$loopIdx] ?? 0.0)        // each lap on its own line
                + 2.5 * sin($loopDist / 41.0);                    // + gentle wander
            [$lat, $lng] = self::step($lat, $lng, $segs[$i]['brg'] + 90.0, $side * $taper);
            return [$lat, $lng];
        };

        self::header($w, $start);
        // Paired sensors, each with its own battery: a chest strap and a
        // running-dynamics footpod alongside the watch (added by header()).
        $w->add('device_info', ['timestamp' => $start, 'device_index' => 1, 'manufacturer' => 'garmin', 'product' => 2697, 'battery_level' => 88]);
        $w->add('device_info', ['timestamp' => $start, 'device_index' => 2, 'manufacturer' => 'garmin', 'product' => 3287, 'battery_level' => 76]);
        self::startEvent($w, $start);

        // A Connect IQ field from a Stryd-style running-power footpod — declared
        // once up front, then carried on the footpod records below.
        $w->add('field_description', [
            'developer_data_index'    => 0,
            'field_definition_number' => 0,
            'fit_base_type_id'        => 'uint16',
            'field_name'              => 'Leg Spring Stiffness',
            'units'                   => 'kN/m',
            'scale'                   => 100,
        ]);

        /** @var list<array{t: int, dist: float, hr: int, cad: int, pow: int}> $ticks */
        $ticks  = [];
        $dynAcc = ['vo' => 0.0, 'gct' => 0.0, 'sl' => 0.0, 'stb' => 0.0, 'n' => 0]; // for session averages
        $cursor = 0.0;
        $t      = 0;
        while (true) {
            $done = $cursor >= $totalLen;
            $dist = $done ? $totalLen : $cursor;
            [$lat, $lng] = $done ? $gate : $pointAt($dist);

            // Ground-truth per-second physiology (independent of recording).
            $pace = self::tiergartenPace($t);
            $cad  = ($pace >= 3.5 ? 184 : 166) + ($t % 5);
            $frac = ($t % 2) * 0.5;                                  // half-step fractional cadence
            $hr   = max(95, min(186, (int) round(120 + ($pace - 2.5) * 28 + min(20.0, $t / 180.0))));
            $resp = max(14.0, min(58.0, round(22.0 + ($hr - 120) * 0.32, 1)));
            $pow  = (int) round($pace * 70.0 + 40.0);               // running power, W
            $slM  = $pace * 60.0 / $cad;                            // meters per step (cadence is always > 0 here)
            $vo   = max(60.0, min(120.0, round(105.0 - $pace * 7.0, 1)));  // vertical oscillation, mm
            $gct  = (int) round(235.0 - ($pace - 2.7) * 30.0);     // ground contact, ms
            $gctp = round(33.0 - ($pace - 2.7) * 1.5, 1);          // stance time, %
            $stb  = round(50.0 + 1.5 * sin($t / 120.0), 1);        // left/right stance balance, %
            $vr   = $slM > 0.0 ? round($vo / ($slM * 1000.0) * 100.0, 1) : 8.0; // vertical ratio, %
            $lss  = round(9.5 + ($cad - 175) * 0.04 + 0.6 * sin($t / 90.0), 2); // leg spring stiffness, kN/m (Stryd)
            $alt  = round(34.0 + 1.5 * sin($t / 300.0) + 0.4 * sin($t / 37.0), 1);

            $ticks[] = ['t' => $t, 'dist' => $dist, 'hr' => $hr, 'cad' => $cad, 'pow' => $pow];
            $dynAcc['vo'] += $vo; $dynAcc['gct'] += $gct; $dynAcc['sl'] += $slM * 1000.0; $dynAcc['stb'] += $stb; $dynAcc['n']++;

            // How the devices recorded this second. Position stays a dense 1 second
            // channel (GPS-derived speed/pace depends on it) apart from two brief
            // dropouts; the variety comes from fragmentation and a footpod that
            // disconnects for one block. Groups: pos = watch GPS, mov = the
            // watch's distance/speed/temp, hr = chest strap, dyn = footpod.
            $ts      = $start->modify("+{$t} sec");
            $block   = ($done || $t < 300) ? -1 : intdiv($t - 300, 300);
            $gpsDrop = !$done && (($t >= 760 && $t < 778) || ($t >= 2680 && $t < 2698)); // canopy / underpass
            $hrLost  = !$done && $t >= 1640 && $t < 1670;                                 // strap reseat
            $footOff = $block === 5;                                                      // footpod disconnected

            $pos = $gpsDrop ? [] : [
                'position_lat'  => $lat,
                'position_long' => $lng,
                'altitude'      => $alt,
                'gps_accuracy'  => 4,
            ];
            $mov = [
                'distance'    => $dist,
                'speed'       => $pace,
                'temperature' => 12,
            ];
            $hrGrp = $hrLost ? [] : [
                'heart_rate'                => $hr,
                'enhanced_respiration_rate' => $resp,
            ];
            $dyn = $footOff ? [] : [
                'cadence'              => $cad,
                'fractional_cadence'   => $frac,
                'power'                => $pow,
                'vertical_oscillation' => $vo,
                'stance_time'          => $gct,
                'stance_time_percent'  => $gctp,
                'stance_time_balance'  => $stb,
                'vertical_ratio'       => $vr,
                'step_length'          => round($slM * 1000.0, 1),
            ];
            // The footpod also reports the Connect IQ "Leg Spring Stiffness" field.
            $devFields = $footOff ? [] : [
                ['fieldNum' => 0, 'devDataIndex' => 0, 'baseType' => BaseType::Uint16, 'value' => (int) round($lss * 100)],
            ];

            if ($block === 2) {
                // Fragmented (Strava-style): position, heart rate and the footpod
                // each arrive in their own message at the same timestamp — so this
                // stretch has position-only and heart-rate-only records.
                if ($pos !== []) {
                    $w->add('record', ['timestamp' => $ts] + $pos);
                }
                if ($hrGrp !== []) {
                    $w->add('record', ['timestamp' => $ts] + $hrGrp);
                }
                $w->add('record', ['timestamp' => $ts] + $mov + $dyn);
            } else {
                // Merged 1 second — one record with whatever the sensors gave us
                // (block 5 has no footpod fields; a dropout omits its group).
                $merged = ['timestamp' => $ts] + $pos + $mov + $hrGrp + $dyn;
                if ($devFields !== []) {
                    $w->addWithDeveloperFields('record', $merged, $devFields);
                } else {
                    $w->add('record', $merged);
                }
            }

            if ($done) {
                break;
            }
            $cursor += $pace;
            $t++;
        }
        $duration = $t; // seconds of the final (clamped) record

        // Even ~1 km auto-laps, so the splits visibly differ on fast vs slow km.
        // Aggregates come from the ground-truth ticks, not the recorded messages.
        $lapCount = max(1, (int) round($totalLen / 1000.0));
        $lapLen   = $totalLen / $lapCount;
        for ($k = 0; $k < $lapCount; $k++) {
            $lo  = $k * $lapLen;
            $hi  = ($k + 1) * $lapLen;
            $last = $k === $lapCount - 1;
            $seg = array_values(array_filter(
                $ticks,
                static fn (array $s): bool => $s['dist'] >= $lo && ($s['dist'] < $hi || ($last && $s['dist'] <= $hi)),
            ));
            if ($seg === []) {
                continue;
            }
            $hrs  = array_column($seg, 'hr');
            $cads = array_column($seg, 'cad');
            $pows = array_column($seg, 'pow');
            $t0   = $seg[0]['t'];
            $tN   = $seg[count($seg) - 1]['t'];
            self::lap(
                $w, $start->modify("+{$t0} sec"), max(1, $tN - $t0 + 1), $lapLen, 'running',
                avgHr: (int) round(array_sum($hrs) / count($hrs)),
                maxHr: max($hrs),
                avgCad: (int) round(array_sum($cads) / count($cads)),
                avgPow: (int) round(array_sum($pows) / count($pows)),
                calories: 65,
            );
        }

        $allHr  = array_column($ticks, 'hr');
        $allCad = array_column($ticks, 'cad');
        $allPow = array_column($ticks, 'pow');
        $end    = $start->modify("+{$duration} sec");
        self::stopEvent($w, $end);
        $nDyn = max(1, $dynAcc['n']);
        self::session(
            $w, $start, $duration, 'running', $totalLen,
            numLaps: $lapCount,
            avgHr: (int) round(array_sum($allHr) / count($allHr)),
            maxHr: max($allHr),
            avgCad: (int) round(array_sum($allCad) / count($allCad)),
            maxCad: max($allCad),
            avgPow: (int) round(array_sum($allPow) / count($allPow)),
            maxPow: max($allPow),
            calories: 780, ascent: 35, descent: 35,
            avgVerticalOscillation: round($dynAcc['vo'] / $nDyn, 1),
            avgStanceTime: round($dynAcc['gct'] / $nDyn, 1),
            avgStepLength: round($dynAcc['sl'] / $nDyn, 1),
            avgStanceTimeBalance: round($dynAcc['stb'] / $nDyn, 1),
        );
        self::activity($w, $end, 1, $duration, utcOffsetSec: 3600); // Berlin, mid-January = UTC+1
        return $w;
    }

    // -----------------------------------------------------------------
    // Cycling
    // -----------------------------------------------------------------

    public static function roadBikePower(): Writer
    {
        $w        = new Writer();
        $start    = self::start();
        $duration = 3600;       // 1 h
        $speedMs  = 9.0;        // ~32 km/h
        [$lat, $lng] = [self::ORIGIN_LAT, self::ORIGIN_LNG];
        $dist = 0.0;
        self::header($w, $start, manufacturer: 'wahoo_fitness');
        self::startEvent($w, $start);
        for ($t = 0; $t < $duration; $t++) {
            $isDescent = ($t > 1800 && $t < 2400);
            $power     = $isDescent ? 0 : 220 + (int) (40 * sin($t / 300.0));
            $cadence   = $isDescent ? 0 : 88 + ($t % 5);
            $w->add('record', [
                'timestamp'    => $start->modify("+{$t} sec"),
                'position_lat' => $lat,
                'position_long'=> $lng,
                'distance'     => $dist,
                'altitude'     => 100.0 + ($isDescent ? -50.0 : 0.0),
                'heart_rate'   => self::hrRamp($t, $duration, 130, 170) - ($isDescent ? 20 : 0),
                'cadence'      => $cadence,
                'power'        => $power,
                'speed'        => $isDescent ? 14.0 : $speedMs,
                'temperature'  => 18,
                'gps_accuracy' => 4,
            ]);
            [$lat, $lng] = self::step($lat, $lng, 60, $isDescent ? 14.0 : $speedMs);
            $dist += $isDescent ? 14.0 : $speedMs;
        }
        $end = $start->modify("+{$duration} sec");
        self::stopEvent($w, $end);
        self::lap($w, $start, $duration, $dist, 'cycling', avgHr: 145, maxHr: 172,
            avgCad: 85, avgPow: 200, maxPow: 320, calories: 720, ascent: 250, descent: 250);
        self::session($w, $start, $duration, 'cycling', $dist, avgHr: 145, maxHr: 172,
            avgCad: 85, avgPow: 200, maxPow: 320, calories: 720, ascent: 250, descent: 250);
        self::activity($w, $end, 1, $duration);
        return $w;
    }

    public static function mtbWithJitter(): Writer
    {
        $w        = new Writer();
        $start    = self::start();
        $duration = 2700; // 45 min
        $speedMs  = 5.5;
        [$lat, $lng] = [self::ORIGIN_LAT, self::ORIGIN_LNG];
        $dist = 0.0;
        // Deterministic jitter using a small LCG so the file is byte-stable.
        $rng = 0xCAFEF00D;
        $nextJitter = static function () use (&$rng): float {
            $rng = ($rng * 1103515245 + 12345) & 0x7FFFFFFF;
            return ($rng / 0x7FFFFFFF - 0.5) * 0.00008; // ~±5 m of degree noise
        };
        self::header($w, $start);
        self::startEvent($w, $start);
        for ($t = 0; $t < $duration; $t++) {
            $w->add('record', [
                'timestamp'    => $start->modify("+{$t} sec"),
                'position_lat' => $lat + $nextJitter(),
                'position_long'=> $lng + $nextJitter(),
                'distance'     => $dist,
                'altitude'     => 250.0 + 80.0 * sin($t / 200.0),
                'heart_rate'   => self::hrRamp($t, $duration, 140, 178),
                'cadence'      => 70 + ($t % 7),
                'speed'        => $speedMs,
                'gps_accuracy' => 12, // poor under tree canopy
            ]);
            [$lat, $lng] = self::step($lat, $lng, 30 + sin($t / 60.0) * 90, $speedMs);
            $dist += $speedMs;
        }
        $end = $start->modify("+{$duration} sec");
        self::stopEvent($w, $end);
        self::lap($w, $start, $duration, $dist, 'cycling', subSport: 'mountain',
            avgHr: 158, maxHr: 182, avgCad: 72, calories: 580, ascent: 320, descent: 320);
        self::session($w, $start, $duration, 'cycling', $dist, subSport: 'mountain',
            avgHr: 158, maxHr: 182, avgCad: 72, calories: 580, ascent: 320, descent: 320);
        self::activity($w, $end, 1, $duration);
        return $w;
    }

    public static function indoorBikeTrainer(): Writer
    {
        $w        = new Writer();
        $start    = self::start();
        $duration = 2700;
        $dist     = 0.0;
        self::header($w, $start, manufacturer: 'wahoo_fitness');
        self::startEvent($w, $start);
        for ($t = 0; $t < $duration; $t++) {
            // Interval pattern: 5 min warmup, then 10 × (3 min @ 280 W, 2 min @ 150 W), then cooldown
            if ($t < 300)          $power = 130 + (int) ($t / 5);
            elseif ($t > 2400)     $power = 140;
            else {
                $cycleT = ($t - 300) % 300;
                $power  = $cycleT < 180 ? 280 : 150;
            }
            $cadence = $power > 200 ? 95 : 80;
            $speed   = $power * 0.04; // pure virtual
            $dist   += $speed;
            $w->add('record', [
                'timestamp'  => $start->modify("+{$t} sec"),
                'distance'   => $dist,
                'speed'      => $speed,
                'power'      => $power,
                'cadence'    => $cadence,
                'heart_rate' => 110 + (int) ($power * 0.18),
            ]);
        }
        $end = $start->modify("+{$duration} sec");
        self::stopEvent($w, $end);
        self::lap($w, $start, $duration, $dist, 'cycling', subSport: 'indoor_cycling',
            avgHr: 152, maxHr: 175, avgCad: 88, avgPow: 220, maxPow: 280, calories: 680);
        self::session($w, $start, $duration, 'cycling', $dist, subSport: 'indoor_cycling',
            avgHr: 152, maxHr: 175, avgCad: 88, avgPow: 220, maxPow: 280, calories: 680);
        self::activity($w, $end, 1, $duration);
        return $w;
    }

    public static function commuteWithAutopause(): Writer
    {
        $w     = new Writer();
        $start = self::start();
        self::header($w, $start);
        self::startEvent($w, $start);
        [$lat, $lng] = [self::ORIGIN_LAT, self::ORIGIN_LNG];
        $cursor = $start;
        $dist = 0.0;
        // 4 legs of 4 minutes riding, 90 s stopped at lights between.
        for ($leg = 0; $leg < 4; $leg++) {
            for ($t = 0; $t < 240; $t++) {
                $w->add('record', [
                    'timestamp'    => $cursor,
                    'position_lat' => $lat,
                    'position_long'=> $lng,
                    'distance'     => $dist,
                    'heart_rate'   => 130 + ($leg * 2),
                    'cadence'      => 78,
                    'speed'        => 6.5,
                ]);
                [$lat, $lng] = self::step($lat, $lng, 0, 6.5);
                $dist  += 6.5;
                $cursor = $cursor->modify('+1 sec');
            }
            if ($leg < 3) {
                $w->add('event', ['timestamp' => $cursor, 'event' => 'timer', 'event_type' => 'stop', 'event_group' => 0]);
                $cursor = $cursor->modify('+90 sec');
                $w->add('event', ['timestamp' => $cursor, 'event' => 'timer', 'event_type' => 'start', 'event_group' => 0]);
            }
        }
        $end = $cursor;
        $totalDur = $end->getTimestamp() - $start->getTimestamp();
        $timerDur = 240 * 4;
        self::stopEvent($w, $end);
        self::lap($w, $start, $totalDur, $dist, 'cycling', avgHr: 134, maxHr: 152, calories: 280);
        self::session($w, $start, $totalDur, 'cycling', $dist,
            avgHr: 134, maxHr: 152, calories: 280, timerSec: $timerDur);
        self::activity($w, $end, 1, $timerDur);
        return $w;
    }

    public static function gravelSmartRecording(): Writer
    {
        $w        = new Writer();
        $start    = self::start();
        $duration = 7200; // 2 h
        $speedMs  = 7.0;
        [$lat, $lng] = [self::ORIGIN_LAT, self::ORIGIN_LNG];
        $dist = 0.0;
        self::header($w, $start);
        self::startEvent($w, $start);
        // Variable cadence: 1 second for most of the ride, ~30 s sparse during a 10 min stop in the middle.
        $stopStart = 4500;
        $stopEnd   = 5100;
        for ($t = 0; $t < $duration; $t++) {
            if ($t >= $stopStart && $t < $stopEnd && ($t - $stopStart) % 30 !== 0) {
                continue;
            }
            $moving = !($t >= $stopStart && $t < $stopEnd);
            $w->add('record', [
                'timestamp'    => $start->modify("+{$t} sec"),
                'position_lat' => $lat,
                'position_long'=> $lng,
                'distance'     => $dist,
                'heart_rate'   => $moving ? self::hrRamp($t, $duration, 140, 165) : 95,
                'cadence'      => $moving ? 82 : 0,
                'speed'        => $moving ? $speedMs : 0.0,
            ]);
            if ($moving) {
                [$lat, $lng] = self::step($lat, $lng, 75, $speedMs);
                $dist += $speedMs;
            }
        }
        $end = $start->modify("+{$duration} sec");
        self::stopEvent($w, $end);
        self::lap($w, $start, $duration, $dist, 'cycling', subSport: 'gravel_cycling',
            avgHr: 150, maxHr: 172, avgCad: 78, calories: 1200, ascent: 180, descent: 180);
        self::session($w, $start, $duration, 'cycling', $dist, subSport: 'gravel_cycling',
            avgHr: 150, maxHr: 172, avgCad: 78, calories: 1200, ascent: 180, descent: 180);
        self::activity($w, $end, 1, $duration);
        return $w;
    }

    public static function ebikeBattery(): Writer
    {
        $w        = new Writer();
        $start    = self::start();
        $duration = 2400; // 40 min
        $speedMs  = 8.5;
        [$lat, $lng] = [self::ORIGIN_LAT, self::ORIGIN_LNG];
        $dist = 0.0;
        self::header($w, $start, manufacturer: 'bosch');
        // Multiple device_info messages with declining battery_level.
        $w->add('device_info', [
            'timestamp'      => $start,
            'device_index'   => 0,
            'manufacturer'   => 'bosch',
            'product'        => 100,
            'source_type'    => 'local',
            'battery_level'  => 95,
        ]);
        self::startEvent($w, $start);
        for ($t = 0; $t < $duration; $t++) {
            if ($t > 0 && $t % 600 === 0) {
                $w->add('device_info', [
                    'timestamp'      => $start->modify("+{$t} sec"),
                    'device_index'   => 0,
                    'manufacturer'   => 'bosch',
                    'product'        => 100,
                    'source_type'    => 'local',
                    'battery_level'  => max(0, 95 - (int) ($t / 60)),
                ]);
            }
            $w->add('record', [
                'timestamp'    => $start->modify("+{$t} sec"),
                'position_lat' => $lat,
                'position_long'=> $lng,
                'distance'     => $dist,
                'heart_rate'   => 118 + (int) (10 * sin($t / 300.0)),
                'cadence'      => 70,
                'power'        => 120, // rider's contribution, motor handles the rest
                'speed'        => $speedMs,
            ]);
            [$lat, $lng] = self::step($lat, $lng, 90, $speedMs);
            $dist += $speedMs;
        }
        $end = $start->modify("+{$duration} sec");
        self::stopEvent($w, $end);
        self::lap($w, $start, $duration, $dist, 'cycling', subSport: 'e_bike_fitness',
            avgHr: 122, maxHr: 138, avgCad: 70, avgPow: 120, calories: 300);
        self::session($w, $start, $duration, 'cycling', $dist, subSport: 'e_bike_fitness',
            avgHr: 122, maxHr: 138, avgCad: 70, avgPow: 120, calories: 300);
        self::activity($w, $end, 1, $duration);
        return $w;
    }

    // -----------------------------------------------------------------
    // Swimming
    // -----------------------------------------------------------------

    public static function poolSwimLaps(): Writer
    {
        $w     = new Writer();
        $start = self::start();
        self::header($w, $start);
        self::startEvent($w, $start);
        $cursor = $start;
        $dist   = 0.0;
        $laps   = 5;
        $lapLen = 100; // m
        for ($i = 0; $i < $laps; $i++) {
            $lapStart = $cursor;
            // 100 m at ~1.4 m/s ~ 71 s
            for ($t = 0; $t < 71; $t++) {
                $w->add('record', [
                    'timestamp'  => $cursor,
                    'distance'   => $dist,
                    'speed'      => 1.4,
                    'heart_rate' => 140 + ($i * 2) + (int) ($t / 10),
                ]);
                $dist  += 1.4;
                $cursor = $cursor->modify('+1 sec');
            }
            self::lap($w, $lapStart, 71, $lapLen, 'swimming', subSport: 'lap_swimming',
                avgHr: 148, maxHr: 158, calories: 25);
            // 30 s rest with no records.
            $cursor = $cursor->modify('+30 sec');
        }
        $end      = $cursor;
        $totalDur = $end->getTimestamp() - $start->getTimestamp();
        self::stopEvent($w, $end);
        self::session($w, $start, $totalDur, 'swimming', $dist,
            subSport: 'lap_swimming', numLaps: $laps,
            avgHr: 150, maxHr: 162, calories: 145);
        self::activity($w, $end, 1, $totalDur);
        return $w;
    }

    public static function openWaterSwim(): Writer
    {
        $w        = new Writer();
        $start    = self::start();
        $duration = 1500; // 25 min
        $speedMs  = 1.1;
        [$lat, $lng] = [self::ORIGIN_LAT, self::ORIGIN_LNG];
        $dist = 0.0;
        self::header($w, $start);
        self::startEvent($w, $start);
        for ($t = 0; $t < $duration; $t++) {
            // GPS only every 5 s (surface fixes); the rest is HR/distance.
            $hasGps = ($t % 5 === 0);
            $fields = [
                'timestamp'  => $start->modify("+{$t} sec"),
                'distance'   => $dist,
                'speed'      => $speedMs,
                'heart_rate' => 138 + (int) (5 * sin($t / 120.0)),
            ];
            if ($hasGps) {
                $fields['position_lat']  = $lat;
                $fields['position_long'] = $lng;
                [$lat, $lng] = self::step($lat, $lng, 0, $speedMs * 5);
            }
            $w->add('record', $fields);
            $dist += $speedMs;
        }
        $end = $start->modify("+{$duration} sec");
        self::stopEvent($w, $end);
        self::lap($w, $start, $duration, $dist, 'swimming', subSport: 'open_water',
            avgHr: 142, maxHr: 154, calories: 270);
        self::session($w, $start, $duration, 'swimming', $dist, subSport: 'open_water',
            avgHr: 142, maxHr: 154, calories: 270);
        self::activity($w, $end, 1, $duration);
        return $w;
    }

    // -----------------------------------------------------------------
    // Walking / Hiking
    // -----------------------------------------------------------------

    public static function casualWalk(): Writer
    {
        $w        = new Writer();
        $start    = self::start();
        $duration = 1800;
        $speedMs  = 1.3;
        [$lat, $lng] = [self::ORIGIN_LAT, self::ORIGIN_LNG];
        $dist = 0.0;
        self::header($w, $start);
        self::startEvent($w, $start);
        for ($t = 0; $t < $duration; $t++) {
            $w->add('record', [
                'timestamp'    => $start->modify("+{$t} sec"),
                'position_lat' => $lat,
                'position_long'=> $lng,
                'distance'     => $dist,
                'heart_rate'   => 95 + (int) (10 * sin($t / 200.0)),
                'cadence'      => 110,
                'speed'        => $speedMs,
            ]);
            [$lat, $lng] = self::step($lat, $lng, 180, $speedMs);
            $dist += $speedMs;
        }
        $end = $start->modify("+{$duration} sec");
        self::stopEvent($w, $end);
        self::lap($w, $start, $duration, $dist, 'walking', avgHr: 100, maxHr: 112, avgCad: 110, calories: 160);
        self::session($w, $start, $duration, 'walking', $dist, avgHr: 100, maxHr: 112, avgCad: 110, calories: 160);
        self::activity($w, $end, 1, $duration);
        return $w;
    }

    public static function walkWithDropouts(): Writer
    {
        $w        = new Writer();
        $start    = self::start();
        $duration = 1200; // 20 min
        $speedMs  = 1.4;
        [$lat, $lng] = [self::ORIGIN_LAT, self::ORIGIN_LNG];
        $dist = 0.0;
        // GPS dropouts: [300..330], [800..830]. HR=0: [500..620].
        self::header($w, $start);
        self::startEvent($w, $start);
        for ($t = 0; $t < $duration; $t++) {
            $gpsDrop = ($t >= 300 && $t < 330) || ($t >= 800 && $t < 830);
            $hrDrop  = ($t >= 500 && $t < 620);
            $fields  = [
                'timestamp'  => $start->modify("+{$t} sec"),
                'distance'   => $dist,
                'speed'      => $speedMs,
                'cadence'    => 108,
                'heart_rate' => $hrDrop ? 0 : (105 + (int) (8 * sin($t / 180.0))),
            ];
            if (!$gpsDrop) {
                $fields['position_lat']  = $lat;
                $fields['position_long'] = $lng;
                [$lat, $lng] = self::step($lat, $lng, 270, $speedMs);
            } else {
                [$lat, $lng] = self::step($lat, $lng, 270, $speedMs); // walker keeps moving
            }
            $w->add('record', $fields);
            $dist += $speedMs;
        }
        $end = $start->modify("+{$duration} sec");
        self::stopEvent($w, $end);
        self::lap($w, $start, $duration, $dist, 'walking', avgHr: 108, maxHr: 118, avgCad: 108, calories: 95);
        self::session($w, $start, $duration, 'walking', $dist, avgHr: 108, maxHr: 118, avgCad: 108, calories: 95);
        self::activity($w, $end, 1, $duration);
        return $w;
    }

    public static function mountainHikeRests(): Writer
    {
        $w        = new Writer();
        $start    = self::start();
        $duration = 14400; // 4 h
        [$lat, $lng] = [self::ORIGIN_LAT, self::ORIGIN_LNG];
        $dist = 0.0;
        $rest1 = [3600, 4500];   // 15 min rest at the 1h mark
        $rest2 = [9000, 9900];   // 15 min at 2.5h
        self::header($w, $start);
        self::startEvent($w, $start);
        for ($t = 0; $t < $duration; $t++) {
            $resting = ($t >= $rest1[0] && $t < $rest1[1]) || ($t >= $rest2[0] && $t < $rest2[1]);
            $speed   = $resting ? 0.0 : 1.1;
            $w->add('record', [
                'timestamp'    => $start->modify("+{$t} sec"),
                'position_lat' => $lat,
                'position_long'=> $lng,
                'distance'     => $dist,
                'altitude'     => 800.0 + 500.0 * (1 - cos(2 * M_PI * $t / $duration)) / 2,
                'heart_rate'   => $resting ? 85 : 125,
                'cadence'      => $resting ? 0 : 95,
                'speed'        => $speed,
                'temperature'  => 6,
            ]);
            if (!$resting) {
                [$lat, $lng] = self::step($lat, $lng, 30, $speed);
                $dist += $speed;
            }
        }
        $end = $start->modify("+{$duration} sec");
        self::stopEvent($w, $end);
        self::lap($w, $start, $duration, $dist, 'hiking', avgHr: 115, maxHr: 145,
            calories: 1800, ascent: 480, descent: 480);
        self::session($w, $start, $duration, 'hiking', $dist, avgHr: 115, maxHr: 145,
            calories: 1800, ascent: 480, descent: 480);
        self::activity($w, $end, 1, $duration);
        return $w;
    }

    // -----------------------------------------------------------------
    // Indoor / Gym
    // -----------------------------------------------------------------

    public static function rowingIndoor(): Writer
    {
        $w        = new Writer();
        $start    = self::start();
        $duration = 1800;
        $strokesPerMin = 26;
        $speedMs  = 4.0;
        $dist = 0.0;
        self::header($w, $start, manufacturer: 'concept2');
        self::startEvent($w, $start);
        for ($t = 0; $t < $duration; $t++) {
            $w->add('record', [
                'timestamp'  => $start->modify("+{$t} sec"),
                'distance'   => $dist,
                'speed'      => $speedMs,
                'cadence'    => $strokesPerMin,
                'heart_rate' => self::hrRamp($t, $duration, 130, 170),
                'power'      => 180 + (int) (20 * sin($t / 60.0)),
            ]);
            $dist += $speedMs;
        }
        $end = $start->modify("+{$duration} sec");
        self::stopEvent($w, $end);
        self::lap($w, $start, $duration, $dist, 'rowing', subSport: 'indoor_rowing',
            avgHr: 152, maxHr: 175, avgCad: 26, avgPow: 185, calories: 320);
        self::session($w, $start, $duration, 'rowing', $dist, subSport: 'indoor_rowing',
            avgHr: 152, maxHr: 175, avgCad: 26, avgPow: 185, calories: 320);
        self::activity($w, $end, 1, $duration);
        return $w;
    }

    public static function strengthTraining(): Writer
    {
        $w        = new Writer();
        $start    = self::start();
        $duration = 2700;
        self::header($w, $start);
        self::startEvent($w, $start);
        for ($t = 0; $t < $duration; $t++) {
            // Pulsing HR: spikes during sets, drops during rests.
            $hr = 95 + (int) (50 * abs(sin($t / 90.0)));
            $w->add('record', [
                'timestamp'  => $start->modify("+{$t} sec"),
                'heart_rate' => $hr,
            ]);
        }
        $end = $start->modify("+{$duration} sec");
        self::stopEvent($w, $end);
        self::lap($w, $start, $duration, null, 'training', subSport: 'strength_training',
            avgHr: 125, maxHr: 158, calories: 250);
        self::session($w, $start, $duration, 'training', 0.0, subSport: 'strength_training',
            avgHr: 125, maxHr: 158, calories: 250);
        self::activity($w, $end, 1, $duration);
        return $w;
    }

    // -----------------------------------------------------------------
    // Winter / Water
    // -----------------------------------------------------------------

    public static function xcSki(): Writer
    {
        $w        = new Writer();
        $start    = self::start();
        $duration = 3600;
        $speedMs  = 4.5;
        [$lat, $lng] = [self::ORIGIN_LAT, self::ORIGIN_LNG];
        $dist = 0.0;
        self::header($w, $start);
        self::startEvent($w, $start);
        for ($t = 0; $t < $duration; $t++) {
            $w->add('record', [
                'timestamp'    => $start->modify("+{$t} sec"),
                'position_lat' => $lat,
                'position_long'=> $lng,
                'distance'     => $dist,
                'altitude'     => 1100.0 + 80.0 * sin($t / 240.0),
                'heart_rate'   => self::hrRamp($t, $duration, 140, 172),
                'cadence'      => 55,            // pole strokes/min
                'speed'        => $speedMs,
                'temperature'  => -7,
            ]);
            [$lat, $lng] = self::step($lat, $lng, 50, $speedMs);
            $dist += $speedMs;
        }
        $end = $start->modify("+{$duration} sec");
        self::stopEvent($w, $end);
        self::lap($w, $start, $duration, $dist, 'cross_country_skiing',
            avgHr: 152, maxHr: 178, avgCad: 55, calories: 880, ascent: 180, descent: 180);
        self::session($w, $start, $duration, 'cross_country_skiing', $dist,
            avgHr: 152, maxHr: 178, avgCad: 55, calories: 880, ascent: 180, descent: 180);
        self::activity($w, $end, 1, $duration);
        return $w;
    }

    public static function kayakPaddle(): Writer
    {
        $w        = new Writer();
        $start    = self::start();
        $duration = 2700;
        $speedMs  = 2.2;
        [$lat, $lng] = [self::ORIGIN_LAT, self::ORIGIN_LNG];
        $dist = 0.0;
        self::header($w, $start);
        self::startEvent($w, $start);
        for ($t = 0; $t < $duration; $t++) {
            $w->add('record', [
                'timestamp'    => $start->modify("+{$t} sec"),
                'position_lat' => $lat,
                'position_long'=> $lng,
                'distance'     => $dist,
                'cadence'      => 42,
                'speed'        => $speedMs,
                'temperature'  => 16,
            ]);
            [$lat, $lng] = self::step($lat, $lng, 120, $speedMs);
            $dist += $speedMs;
        }
        $end = $start->modify("+{$duration} sec");
        self::stopEvent($w, $end);
        self::lap($w, $start, $duration, $dist, 'paddling', avgCad: 42, calories: 360);
        self::session($w, $start, $duration, 'paddling', $dist, avgCad: 42, calories: 360);
        self::activity($w, $end, 1, $duration);
        return $w;
    }

    // -----------------------------------------------------------------
    // Multisport
    // -----------------------------------------------------------------

    public static function multisportTriathlon(): Writer
    {
        $w      = new Writer();
        $start  = self::start();
        $cursor = $start;
        self::header($w, $start);
        self::startEvent($w, $cursor);
        // Sprint distances: 750 m swim, 20 km bike, 5 km run.
        $cursor = self::leg($w, $cursor, durationSec: 750,  sport: 'swimming',
            distance: 750.0, speedMs: 1.0,  hrBase: 145);
        $cursor = $cursor->modify('+90 sec');  // T1
        $cursor = self::leg($w, $cursor, durationSec: 2400, sport: 'cycling',
            distance: 20000.0, speedMs: 8.33, hrBase: 150);
        $cursor = $cursor->modify('+60 sec');  // T2
        $cursor = self::leg($w, $cursor, durationSec: 1500, sport: 'running',
            distance: 5000.0, speedMs: 3.33, hrBase: 165);
        $end      = $cursor;
        $totalDur = $end->getTimestamp() - $start->getTimestamp();
        self::stopEvent($w, $end);
        self::activity($w, $end, 3, $totalDur);
        return $w;
    }

    public static function multisportDuathlon(): Writer
    {
        $w      = new Writer();
        $start  = self::start();
        $cursor = $start;
        self::header($w, $start);
        self::startEvent($w, $cursor);
        $cursor = self::leg($w, $cursor, durationSec: 1200, sport: 'running',
            distance: 4000.0, speedMs: 3.33, hrBase: 160);
        $cursor = $cursor->modify('+60 sec');
        $cursor = self::leg($w, $cursor, durationSec: 1800, sport: 'cycling',
            distance: 15000.0, speedMs: 8.33, hrBase: 155);
        $cursor = $cursor->modify('+60 sec');
        $cursor = self::leg($w, $cursor, durationSec: 1200, sport: 'running',
            distance: 4000.0, speedMs: 3.33, hrBase: 168);
        $end      = $cursor;
        $totalDur = $end->getTimestamp() - $start->getTimestamp();
        self::stopEvent($w, $end);
        self::activity($w, $end, 3, $totalDur);
        return $w;
    }

    // -----------------------------------------------------------------
    // Edge cases
    // -----------------------------------------------------------------

    public static function veryShort(): Writer
    {
        $w     = new Writer();
        $start = self::start();
        self::header($w, $start);
        self::startEvent($w, $start);
        [$lat, $lng] = [self::ORIGIN_LAT, self::ORIGIN_LNG];
        $dist = 0.0;
        for ($t = 0; $t < 5; $t++) {
            $w->add('record', [
                'timestamp'    => $start->modify("+{$t} sec"),
                'position_lat' => $lat,
                'position_long'=> $lng,
                'distance'     => $dist,
                'heart_rate'   => 110 + $t,
                'speed'        => 3.0,
            ]);
            [$lat, $lng] = self::step($lat, $lng, 0, 3.0);
            $dist += 3.0;
        }
        $end = $start->modify('+5 sec');
        self::stopEvent($w, $end);
        self::lap($w, $start, 5, $dist, 'running', avgHr: 112, maxHr: 114, calories: 2);
        self::session($w, $start, 5, 'running', $dist, avgHr: 112, maxHr: 114, calories: 2);
        self::activity($w, $end, 1, 5);
        return $w;
    }

    public static function pausedLongGap(): Writer
    {
        $w        = new Writer();
        $start    = self::start();
        $pauseAt  = 600;
        $resumeAt = 600 + 1800; // 30 min pause
        $duration = 1800;        // 30 min of moving time
        $speedMs  = 8.0;
        [$lat, $lng] = [self::ORIGIN_LAT, self::ORIGIN_LNG];
        $dist = 0.0;
        self::header($w, $start);
        self::startEvent($w, $start);
        for ($t = 0; $t < $pauseAt; $t++) {
            $w->add('record', [
                'timestamp'    => $start->modify("+{$t} sec"),
                'position_lat' => $lat,
                'position_long'=> $lng,
                'distance'     => $dist,
                'heart_rate'   => 142,
                'speed'        => $speedMs,
            ]);
            [$lat, $lng] = self::step($lat, $lng, 60, $speedMs);
            $dist += $speedMs;
        }
        $pauseTs = $start->modify("+{$pauseAt} sec");
        $w->add('event', ['timestamp' => $pauseTs, 'event' => 'timer', 'event_type' => 'stop', 'event_group' => 0]);
        $resumeTs = $start->modify("+{$resumeAt} sec");
        $w->add('event', ['timestamp' => $resumeTs, 'event' => 'timer', 'event_type' => 'start', 'event_group' => 0]);
        for ($t = $pauseAt; $t < $duration; $t++) {
            $clockT = $resumeAt + ($t - $pauseAt);
            $w->add('record', [
                'timestamp'    => $start->modify("+{$clockT} sec"),
                'position_lat' => $lat,
                'position_long'=> $lng,
                'distance'     => $dist,
                'heart_rate'   => 145,
                'speed'        => $speedMs,
            ]);
            [$lat, $lng] = self::step($lat, $lng, 60, $speedMs);
            $dist += $speedMs;
        }
        $end = $start->modify('+' . ($resumeAt + ($duration - $pauseAt)) . ' sec');
        self::stopEvent($w, $end);
        $totalElapsed = $end->getTimestamp() - $start->getTimestamp();
        self::lap($w, $start, $totalElapsed, $dist, 'cycling',
            avgHr: 143, maxHr: 152, calories: 250, timerSec: $duration);
        self::session($w, $start, $totalElapsed, 'cycling', $dist,
            avgHr: 143, maxHr: 152, calories: 250, timerSec: $duration);
        self::activity($w, $end, 1, $duration);
        return $w;
    }

    public static function noSessionAggregates(): Writer
    {
        $w        = new Writer();
        $start    = self::start();
        $duration = 1200;
        $speedMs  = 3.0;
        [$lat, $lng] = [self::ORIGIN_LAT, self::ORIGIN_LNG];
        $dist = 0.0;
        self::header($w, $start);
        self::startEvent($w, $start);
        for ($t = 0; $t < $duration; $t++) {
            $w->add('record', [
                'timestamp'    => $start->modify("+{$t} sec"),
                'position_lat' => $lat,
                'position_long'=> $lng,
                'distance'     => $dist,
                'heart_rate'   => 140 + (int) (15 * sin($t / 60.0)),
                'cadence'      => 175,
                'power'        => 240 + (int) (30 * sin($t / 90.0)),
                'speed'        => $speedMs,
            ]);
            [$lat, $lng] = self::step($lat, $lng, 0, $speedMs);
            $dist += $speedMs;
        }
        $end = $start->modify("+{$duration} sec");
        self::stopEvent($w, $end);
        // Deliberately omit avg/max HR/cad/power/calories — forces fallback.
        $w->add('lap', [
            'timestamp'          => $end,
            'start_time'         => $start,
            'total_elapsed_time' => (float) $duration,
            'total_timer_time'   => (float) $duration,
            'total_distance'     => $dist,
            'sport'              => 'running',
            'event'              => 'lap',
            'event_type'         => 'stop',
        ]);
        $w->add('session', [
            'timestamp'          => $end,
            'start_time'         => $start,
            'total_elapsed_time' => (float) $duration,
            'total_timer_time'   => (float) $duration,
            'total_distance'     => $dist,
            'sport'              => 'running',
            'num_laps'           => 1,
            'event'              => 'session',
            'event_type'         => 'stop',
        ]);
        self::activity($w, $end, 1, $duration);
        return $w;
    }

    public static function multiHourEndurance(): Writer
    {
        $w        = new Writer();
        $start    = self::start();
        $duration = 18000; // 5 h
        $speedMs  = 8.0;
        [$lat, $lng] = [self::ORIGIN_LAT, self::ORIGIN_LNG];
        $dist = 0.0;
        $cursor = $start;
        self::header($w, $start);
        self::startEvent($w, $start);
        // Smart recording: 1 record every 3 s.
        for ($t = 0; $t < $duration; $t += 3) {
            $w->add('record', [
                'timestamp'    => $start->modify("+{$t} sec"),
                'position_lat' => $lat,
                'position_long'=> $lng,
                'distance'     => $dist,
                'altitude'     => 200.0 + 150.0 * sin($t / 1800.0),
                'heart_rate'   => 138 + (int) (8 * sin($t / 600.0)),
                'cadence'      => 80,
                'power'        => 180 + (int) (20 * sin($t / 500.0)),
                'speed'        => $speedMs,
                'temperature'  => 22,
            ]);
            [$lat, $lng] = self::step($lat, $lng, 90, $speedMs * 3);
            $dist += $speedMs * 3;
        }
        // 5 laps of equal duration.
        $lapDur = (int) ($duration / 5);
        for ($i = 0; $i < 5; $i++) {
            $lapStart = $start->modify('+' . ($i * $lapDur) . ' sec');
            self::lap($w, $lapStart, $lapDur, $speedMs * $lapDur, 'cycling',
                avgHr: 142, maxHr: 158, avgCad: 80, avgPow: 185, calories: 400, ascent: 250, descent: 250);
        }
        $end = $start->modify("+{$duration} sec");
        self::stopEvent($w, $end);
        self::session($w, $start, $duration, 'cycling', $dist,
            numLaps: 5, avgHr: 142, maxHr: 168, avgCad: 80, avgPow: 185, maxPow: 280,
            calories: 2000, ascent: 1250, descent: 1250);
        self::activity($w, $end, 1, $duration);
        return $w;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private static function start(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::START);
    }

    private static function header(Writer $w, \DateTimeImmutable $when, string $manufacturer = 'garmin'): void
    {
        $w->add('file_id', [
            'type'          => 'activity',
            'manufacturer'  => $manufacturer,
            'product'       => 1234,
            'serial_number' => 1729,
            'time_created'  => $when,
        ]);
        $w->add('file_creator', ['software_version' => 100, 'hardware_version' => 1]);
        $w->add('device_info', [
            'timestamp'      => $when,
            'device_index'   => 0,
            'manufacturer'   => $manufacturer,
            'product'        => 1234,
            'software_version' => 100,
            'source_type'    => 'local',
        ]);
    }

    private static function startEvent(Writer $w, \DateTimeImmutable $when): void
    {
        $w->add('event', ['timestamp' => $when, 'event' => 'timer', 'event_type' => 'start', 'event_group' => 0]);
    }

    private static function stopEvent(Writer $w, \DateTimeImmutable $when): void
    {
        $w->add('event', ['timestamp' => $when, 'event' => 'timer', 'event_type' => 'stop_all', 'event_group' => 0]);
    }

    private static function lap(
        Writer $w,
        \DateTimeImmutable $start,
        int $durationSec,
        ?float $distance,
        string $sport,
        ?string $subSport = null,
        ?int $avgHr = null,
        ?int $maxHr = null,
        ?int $avgCad = null,
        ?int $maxCad = null,
        ?int $avgPow = null,
        ?int $maxPow = null,
        ?int $calories = null,
        ?int $ascent = null,
        ?int $descent = null,
        ?int $timerSec = null,
    ): void {
        $fields = [
            'timestamp'          => $start->modify("+{$durationSec} sec"),
            'start_time'         => $start,
            'total_elapsed_time' => (float) $durationSec,
            'total_timer_time'   => (float) ($timerSec ?? $durationSec),
            'sport'              => $sport,
            'event'              => 'lap',
            'event_type'         => 'stop',
        ];
        if ($subSport !== null) $fields['sub_sport'] = $subSport;
        if ($distance !== null) $fields['total_distance'] = $distance;
        if ($avgHr   !== null)  $fields['avg_heart_rate'] = $avgHr;
        if ($maxHr   !== null)  $fields['max_heart_rate'] = $maxHr;
        if ($avgCad  !== null)  $fields['avg_cadence']    = $avgCad;
        if ($maxCad  !== null)  $fields['max_cadence']    = $maxCad;
        if ($avgPow  !== null)  $fields['avg_power']      = $avgPow;
        if ($maxPow  !== null)  $fields['max_power']      = $maxPow;
        if ($calories!== null)  $fields['total_calories'] = $calories;
        if ($ascent  !== null)  $fields['total_ascent']   = $ascent;
        if ($descent !== null)  $fields['total_descent']  = $descent;
        $w->add('lap', $fields);
    }

    private static function session(
        Writer $w,
        \DateTimeImmutable $start,
        int $durationSec,
        string $sport,
        float $distance,
        ?string $subSport = null,
        int $numLaps = 1,
        ?int $avgHr = null,
        ?int $maxHr = null,
        ?int $avgCad = null,
        ?int $maxCad = null,
        ?int $avgPow = null,
        ?int $maxPow = null,
        ?int $calories = null,
        ?int $ascent = null,
        ?int $descent = null,
        ?int $timerSec = null,
        ?float $avgVerticalOscillation = null,
        ?float $avgStanceTime = null,
        ?float $avgStepLength = null,
        ?float $avgStanceTimeBalance = null,
    ): void {
        $fields = [
            'timestamp'          => $start->modify("+{$durationSec} sec"),
            'start_time'         => $start,
            'total_elapsed_time' => (float) $durationSec,
            'total_timer_time'   => (float) ($timerSec ?? $durationSec),
            'total_distance'     => $distance,
            'sport'              => $sport,
            'num_laps'           => $numLaps,
            'event'              => 'session',
            'event_type'         => 'stop',
        ];
        if ($subSport !== null) $fields['sub_sport'] = $subSport;
        if ($avgHr   !== null)  $fields['avg_heart_rate'] = $avgHr;
        if ($maxHr   !== null)  $fields['max_heart_rate'] = $maxHr;
        if ($avgCad  !== null)  $fields['avg_cadence']    = $avgCad;
        if ($maxCad  !== null)  $fields['max_cadence']    = $maxCad;
        if ($avgPow  !== null)  $fields['avg_power']      = $avgPow;
        if ($maxPow  !== null)  $fields['max_power']      = $maxPow;
        if ($calories!== null)  $fields['total_calories'] = $calories;
        if ($ascent  !== null)  $fields['total_ascent']   = $ascent;
        if ($descent !== null)  $fields['total_descent']  = $descent;
        if ($avgVerticalOscillation !== null) $fields['avg_vertical_oscillation'] = $avgVerticalOscillation;
        if ($avgStanceTime          !== null) $fields['avg_stance_time']          = $avgStanceTime;
        if ($avgStepLength          !== null) $fields['avg_step_length']          = $avgStepLength;
        if ($avgStanceTimeBalance   !== null) $fields['avg_stance_time_balance']  = $avgStanceTimeBalance;
        $w->add('session', $fields);
    }

    private static function activity(Writer $w, \DateTimeImmutable $end, int $sessions, int $totalTimerSec, int $utcOffsetSec = 0): void
    {
        $fields = [
            'timestamp'        => $end,
            'total_timer_time' => (float) $totalTimerSec,
            'num_sessions'     => $sessions,
            'type'             => 'manual',
            'event'            => 'activity',
            'event_type'       => 'stop',
        ];
        // local_timestamp is the same instant expressed in local wall-clock,
        // stored as FIT seconds (no profile date_time conversion), so the
        // decoder leaves it an int and Activity::utcOffsetSeconds() can recover it.
        if ($utcOffsetSec !== 0) {
            $fields['local_timestamp'] = ($end->getTimestamp() - FitTimestamp::FIT_EPOCH_OFFSET) + $utcOffsetSec;
        }
        $w->add('activity', $fields);
    }

    /** One sport leg for a multisport activity — records + lap + session. */
    private static function leg(
        Writer $w,
        \DateTimeImmutable $start,
        int $durationSec,
        string $sport,
        float $distance,
        float $speedMs,
        int $hrBase,
    ): \DateTimeImmutable {
        $hasGps = $sport !== 'swimming';
        [$lat, $lng] = [self::ORIGIN_LAT, self::ORIGIN_LNG];
        $dist = 0.0;
        for ($t = 0; $t < $durationSec; $t++) {
            $fields = [
                'timestamp'  => $start->modify("+{$t} sec"),
                'distance'   => $dist,
                'speed'      => $speedMs,
                'heart_rate' => $hrBase + (int) (10 * sin($t / 60.0)),
            ];
            if ($hasGps) {
                $fields['position_lat']  = $lat;
                $fields['position_long'] = $lng;
                [$lat, $lng] = self::step($lat, $lng, 90, $speedMs);
            }
            $w->add('record', $fields);
            $dist += $speedMs;
        }
        $end = $start->modify("+{$durationSec} sec");
        self::lap($w, $start, $durationSec, $distance, $sport,
            avgHr: $hrBase + 5, maxHr: $hrBase + 15, calories: (int) ($durationSec / 5));
        self::session($w, $start, $durationSec, $sport, $distance,
            avgHr: $hrBase + 5, maxHr: $hrBase + 15, calories: (int) ($durationSec / 5));
        return $end;
    }

    /** Simple linear-ramp HR curve from $base to $peak across $duration. */
    private static function hrRamp(int $t, int $duration, int $base, int $peak): int
    {
        $fraction = $t / max(1, $duration - 1);
        return $base + (int) round(($peak - $base) * $fraction);
    }

    /** Walk forward $meters on a great-circle path starting from ($lat, $lng) on bearing degrees. */
    private static function step(float $lat, float $lng, float $bearingDeg, float $meters): array
    {
        $earth   = 6_371_008.8;
        $angDist = $meters / $earth;
        $bearing = deg2rad($bearingDeg);
        $lat1    = deg2rad($lat);
        $lng1    = deg2rad($lng);
        $lat2 = asin(sin($lat1) * cos($angDist) + cos($lat1) * sin($angDist) * cos($bearing));
        $lng2 = $lng1 + atan2(
            sin($bearing) * sin($angDist) * cos($lat1),
            cos($angDist) - sin($lat1) * sin($lat2),
        );
        return [rad2deg($lat2), rad2deg($lng2)];
    }

    /** @param list<array{0: float, 1: float}> $points */
    private static function polylineLength(array $points): float
    {
        $sum = 0.0;
        for ($i = 0, $n = count($points) - 1; $i < $n; $i++) {
            $sum += self::distanceMeters($points[$i][0], $points[$i][1], $points[$i + 1][0], $points[$i + 1][1]);
        }
        return $sum;
    }

    /** Great-circle distance in meters between two lat/lng points (haversine). */
    private static function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6_371_008.8;
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dp = deg2rad($lat2 - $lat1);
        $dl = deg2rad($lng2 - $lng1);
        $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Initial great-circle bearing in degrees (0–360) from point 1 to point 2. */
    private static function bearingDeg(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dl = deg2rad($lng2 - $lng1);
        $y = sin($dl) * cos($p2);
        $x = cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl);
        return fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0);
    }

    /** Fartlek pace (m/s) at second $t: easy warmup, then alternating fast/slow 5-minute blocks. */
    private static function tiergartenPace(int $t): float
    {
        if ($t < 300) {
            return 2.6 + 0.4 * ($t / 300.0); // warmup 2.6 → 3.0 m/s
        }
        return intdiv($t - 300, 300) % 2 === 0 ? 4.0 : 2.7; // ~4:10/km vs ~6:10/km
    }
}
