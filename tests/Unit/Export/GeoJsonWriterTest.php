<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Unit\Export;

use Emontis\FitReader\Activity\Activity;
use Emontis\FitReader\Activity\Record;
use Emontis\FitReader\Activity\Session;
use Emontis\FitReader\Export\GeoJsonWriter;
use Emontis\FitReader\Value\GeoPoint;
use PHPUnit\Framework\TestCase;

final class GeoJsonWriterTest extends TestCase
{
    public function testProducesAFeatureCollectionWithOneLineStringPerSession(): void
    {
        $doc = self::decode(new GeoJsonWriter()->toString(self::sampleActivity()));

        self::assertSame('FeatureCollection', $doc['type']);
        self::assertCount(1, $doc['features']);
        self::assertSame('Feature', $doc['features'][0]['type']);
        self::assertSame('LineString', $doc['features'][0]['geometry']['type']);
        self::assertSame(['session' => 1, 'sport' => 'running'], $doc['features'][0]['properties']);
    }

    public function testCoordinatesAreInLngLatOrder(): void
    {
        $doc    = self::decode(new GeoJsonWriter()->toString(self::sampleActivity()));
        $coords = $doc['features'][0]['geometry']['coordinates'];

        self::assertCount(3, $coords);
        // GeoJSON position is [lng, lat]: longitude (~13.35) first, latitude (~52.51) second.
        self::assertEqualsWithDelta(13.35, $coords[0][0], 0.01, 'GeoJSON coordinate is lng first');
        self::assertEqualsWithDelta(52.51, $coords[0][1], 0.01);
    }

    public function testPositionlessRecordsAreDropped(): void
    {
        $records = [
            new Record(['position' => new GeoPoint(52.51, 13.35)]),
            new Record(['heart_rate' => 150]), // no position — skipped
            new Record(['position' => new GeoPoint(52.52, 13.36)]),
        ];
        $activity = self::wrap(new Session(['sport' => 'running'], [], $records));

        $doc = self::decode(new GeoJsonWriter()->toString($activity));

        self::assertCount(2, $doc['features'][0]['geometry']['coordinates']);
    }

    public function testSessionWithFewerThanTwoFixesIsOmitted(): void
    {
        $activity = self::wrap(new Session(
            ['sport' => 'running'],
            [],
            [new Record(['position' => new GeoPoint(52.51, 13.35)])], // single fix
        ));

        $doc = self::decode(new GeoJsonWriter()->toString($activity));

        self::assertSame([], $doc['features']);
    }

    public function testResolveRecordsCallbackIsHonored(): void
    {
        $activity = self::sampleActivity();
        // Resolver that returns no records ⇒ no drawable feature.
        $doc = self::decode(new GeoJsonWriter()->toString($activity, static fn (Session $s): array => []));

        self::assertSame([], $doc['features']);
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        $doc = json_decode($json, true);
        self::assertIsArray($doc, 'GeoJSON output must be valid JSON');
        return $doc;
    }

    private static function sampleActivity(): Activity
    {
        $records = [];
        for ($i = 0; $i < 3; $i++) {
            $records[] = new Record(['position' => new GeoPoint(52.51 + $i * 0.001, 13.35 + $i * 0.001)]);
        }
        return self::wrap(new Session(['sport' => 'running'], [], $records));
    }

    private static function wrap(Session $session): Activity
    {
        return new Activity([], null, [$session], [], [], null);
    }
}
