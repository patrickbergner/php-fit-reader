<?php

declare(strict_types=1);

namespace Emontis\FitReader\Export;

use Emontis\FitReader\Activity\Activity;
use Emontis\FitReader\Activity\Record;
use Emontis\FitReader\Activity\Session;
use Emontis\FitReader\Geo\DouglasPeucker;
use Emontis\FitReader\Geo\EncodedPolyline;
use Emontis\FitReader\Geo\TrackPoints;

/**
 * Render the GPS track(s) of an Activity as a single PNG via the Mapbox
 * Static Images API. One overlay per session, colors cycled from the
 * palette. Tracks are simplified with {@see DouglasPeucker} and encoded
 * with {@see EncodedPolyline} so the resulting URL fits inside Mapbox's
 * 8,192-character cap; if simplification can't fit the route, the
 * renderer returns null instead of throwing.
 *
 * The caller controls which records each session contributes via the
 * `$resolveRecords` callback (`fn(Session): iterable<Record>`). Default
 * is the session's raw records, which is what
 * {@see \Emontis\FitReader\FitReader::activityToMapPng()} passes: a map
 * only needs `position`, so the forward-filled 1-second timeline would add no
 * detail — just more points for the simplifier to discard. Only records
 * carrying a `position` are kept either way.
 *
 * The renderer **soft-fails** on any expected reason it can't produce a
 * PNG (no GPS, network/HTTP error, URL still too long after 8 simplify
 * passes). Callers get either bytes/true on success, or null/false on a
 * soft failure — never an exception. The only thing that throws is an
 * empty access token, which is a programmer error.
 */
final class MapboxRenderer
{
    private const URL_BUDGET = 7800;
    private const MAX_SIMPLIFY_PASSES = 8;
    private const HTTP_TIMEOUT_SECONDS = 10;
    private const ENDPOINT = 'https://api.mapbox.com/styles/v1/';

    /**
     * @param list<string> $palette Hex colors (no leading '#'), cycled per session.
     */
    public function __construct(
        private readonly string $accessToken,
        private readonly string $styleId = 'mapbox/outdoors-v12',
        private readonly int $widthLogical = 1280,
        private readonly int $heightLogical = 1280,
        private readonly array $palette = ['EA1D2C', '1A73E8', '34A853', 'FBBC04', '9C27B0'],
        private readonly int $strokeWidth = 4,
    ) {
        if ($this->accessToken === '') {
            throw new \InvalidArgumentException('Mapbox access token cannot be empty.');
        }
    }

    /**
     * @param (callable(Session): iterable<Record>)|null $resolveRecords
     */
    public function render(Activity $activity, ?callable $resolveRecords = null): ?string
    {
        $resolveRecords ??= static fn (Session $s): array => $s->records;
        $sessionTracks = $this->collectTracks($activity, $resolveRecords);
        if ($sessionTracks === []) {
            return null;
        }

        $url = $this->buildUrl($sessionTracks);
        if ($url === null) {
            trigger_error(
                'MapboxRenderer: track too long to fit Mapbox URL after simplification.',
                E_USER_WARNING,
            );
            return null;
        }

        return $this->fetch($url);
    }

    /**
     * @param (callable(Session): iterable<Record>)|null $resolveRecords
     */
    public function writeFile(Activity $activity, string $path, ?callable $resolveRecords = null): bool
    {
        $bytes = $this->render($activity, $resolveRecords);
        if ($bytes === null) {
            return false;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            trigger_error(
                "MapboxRenderer: cannot create directory {$dir}.",
                E_USER_WARNING,
            );
            return false;
        }
        if (file_put_contents($path, $bytes) === false) {
            trigger_error(
                "MapboxRenderer: cannot write PNG to {$path}.",
                E_USER_WARNING,
            );
            return false;
        }
        return true;
    }

    /**
     * @param callable(Session): iterable<Record> $resolveRecords
     * @return list<list<array{0: float, 1: float}>> One list per session with ≥ 2 GPS points.
     */
    private function collectTracks(Activity $activity, callable $resolveRecords): array
    {
        $tracks = [];
        foreach ($activity->sessions as $session) {
            $points = TrackPoints::of($resolveRecords($session));
            if (count($points) >= 2) {
                $tracks[] = $points;
            }
        }
        return $tracks;
    }

    /**
     * @param list<list<array{0: float, 1: float}>> $sessionTracks
     */
    private function buildUrl(array $sessionTracks): ?string
    {
        $tolerance = 1e-5; // ~1 m at the equator
        for ($pass = 0; $pass < self::MAX_SIMPLIFY_PASSES; $pass++) {
            $overlays = [];
            foreach ($sessionTracks as $i => $points) {
                $simplified = DouglasPeucker::simplify($points, $tolerance);
                if (count($simplified) < 2) {
                    $simplified = [$points[0], $points[count($points) - 1]];
                }
                $color = $this->palette[$i % count($this->palette)];
                $encoded = EncodedPolyline::encode($simplified);
                $overlays[] = sprintf(
                    'path-%d+%s(%s)',
                    $this->strokeWidth,
                    $color,
                    rawurlencode($encoded),
                );
            }
            $url = sprintf(
                '%s%s/static/%s/auto/%dx%d@2x?access_token=%s&padding=60',
                self::ENDPOINT,
                $this->styleId,
                implode(',', $overlays),
                $this->widthLogical,
                $this->heightLogical,
                rawurlencode($this->accessToken),
            );
            if (strlen($url) <= self::URL_BUDGET) {
                return $url;
            }
            $tolerance *= 2.0;
        }
        return null;
    }

    private function fetch(string $url): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            trigger_error('MapboxRenderer: curl_init failed.', E_USER_WARNING);
            return null;
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::HTTP_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::HTTP_TIMEOUT_SECONDS);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);

        if ($body === false || !is_string($body)) {
            trigger_error("MapboxRenderer: curl error: {$err}", E_USER_WARNING);
            return null;
        }
        if ($status !== 200) {
            trigger_error(
                "MapboxRenderer: Mapbox returned HTTP {$status} (body: " .
                substr($body, 0, 200) . ')',
                E_USER_WARNING,
            );
            return null;
        }
        if (!str_starts_with($contentType, 'image/png')) {
            trigger_error(
                "MapboxRenderer: unexpected Content-Type '{$contentType}'.",
                E_USER_WARNING,
            );
            return null;
        }
        return $body;
    }
}
