<?php

declare(strict_types=1);

namespace Emontis\FitReader\Tests\Unit\Export;

use Emontis\FitReader\Activity\Activity;
use Emontis\FitReader\Activity\Record;
use Emontis\FitReader\Activity\Session;
use Emontis\FitReader\Export\KmlWriter;
use Emontis\FitReader\Value\GeoPoint;
use PHPUnit\Framework\TestCase;

final class KmlWriterTest extends TestCase
{
    public function testTimedRecordsProduceGxTrackWithPairedWhenAndCoord(): void
    {
        $xml   = (new KmlWriter())->toString(self::sampleActivity(withTimestamps: true));
        $xpath = self::xpath($xml);

        $whenCount  = (int) $xpath->evaluate('count(//gx:Track/k:when)');
        $coordCount = (int) $xpath->evaluate('count(//gx:Track/gx:coord)');
        self::assertSame(3, $whenCount);
        self::assertSame($whenCount, $coordCount, '<when> and <gx:coord> must be paired');

        self::assertSame(1, (int) $xpath->evaluate('count(//k:Style/k:LineStyle)'));

        // gx:coord is lon-first, space-separated.
        $first = $xpath->evaluate('string((//gx:Track/gx:coord)[1])');
        [$lon, $lat] = explode(' ', $first);
        self::assertEqualsWithDelta(13.35, (float) $lon, 0.01, 'KML coord is lon first');
        self::assertEqualsWithDelta(52.51, (float) $lat, 0.01);
    }

    public function testHeartRateAndCadenceRideAlongAsArrays(): void
    {
        $xml   = (new KmlWriter())->toString(self::sampleActivity(withTimestamps: true));
        $xpath = self::xpath($xml);

        self::assertSame(1, (int) $xpath->evaluate('count(//gx:SimpleArrayData[@name="heartrate"])'));
        self::assertSame(3, (int) $xpath->evaluate('count(//gx:SimpleArrayData[@name="heartrate"]/gx:value)'));
        self::assertSame(1, (int) $xpath->evaluate('count(//gx:SimpleArrayData[@name="cadence"])'));
    }

    public function testTimestamplessRecordsFallBackToLineString(): void
    {
        $xml   = (new KmlWriter())->toString(self::sampleActivity(withTimestamps: false));
        $xpath = self::xpath($xml);

        self::assertSame(0, (int) $xpath->evaluate('count(//gx:Track)'));
        self::assertSame(1, (int) $xpath->evaluate('count(//k:Placemark/k:LineString/k:coordinates)'));
    }

    private static function xpath(string $xml): \DOMXPath
    {
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml), 'KML output must be well-formed XML');
        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('k', KmlWriter::NS_KML);
        $xpath->registerNamespace('gx', KmlWriter::NS_GX);
        return $xpath;
    }

    private static function sampleActivity(bool $withTimestamps): Activity
    {
        $t0 = new \DateTimeImmutable('2026-06-04T08:00:00Z');
        $records = [];
        for ($i = 0; $i < 3; $i++) {
            $fields = [
                'position'   => new GeoPoint(52.51 + $i * 0.001, 13.35 + $i * 0.001),
                'altitude'   => 35.0 + $i,
                'heart_rate' => 140 + $i,
                'cadence'    => 85 + $i,
            ];
            if ($withTimestamps) {
                $fields['timestamp'] = $t0->modify("+{$i} seconds");
            }
            $records[] = new Record($fields);
        }

        $session = new Session(['sport' => 'running', 'start_time' => $t0], [], $records);

        return new Activity(
            ['manufacturer' => 'garmin', 'time_created' => $t0],
            null,
            [$session],
            [],
            [],
            null,
        );
    }
}
