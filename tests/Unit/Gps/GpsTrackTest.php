<?php

namespace App\Tests\Unit\Gps;

use App\Gps\GpsTrack;
use PHPUnit\Framework\TestCase;

class GpsTrackTest extends TestCase
{
    /**
     * Independent reference implementation of the haversine great-circle distance,
     * used to check GpsTrack's own distance() math without calling it directly (it's private).
     */
    private static function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371e3;
        $fi1 = $lat1 * M_PI / 180;
        $fi2 = $lat2 * M_PI / 180;
        $deltaFi = ($lat2 - $lat1) * M_PI / 180;
        $deltaLambda = ($lon2 - $lon1) * M_PI / 180;

        $a = sin($deltaFi / 2) ** 2 + cos($fi1) * cos($fi2) * sin($deltaLambda / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    public function testProcessComputesPerPointDistanceAndElevationDiffs(): void
    {
        $track = new GpsTrack();
        $track->process(__DIR__ . '/../../Fixtures/gpx/valid-track.gpx');

        $legDistance = self::haversineMeters(0.0, 0.0, 0.0, 0.001);
        $points = $track->getPoints();

        // The fixture has 3 trkpt; process() drops the last one (it only emits a diff
        // per point that has a following point), so 2 diff entries are expected.
        self::assertCount(2, $points);

        self::assertSame(0.0, $points[0]['latitude']);
        self::assertSame(0.0, $points[0]['longitude']);
        self::assertSame(0.0, $points[0]['elevation']);
        self::assertEqualsWithDelta($legDistance, $points[0]['distance'], 0.01);
        self::assertEqualsWithDelta($legDistance, $points[0]['totalDistance'], 0.01);
        // ele goes 0.0 -> 10.0 across this leg.
        self::assertSame(10.0, $points[0]['vDistance']);

        self::assertSame(0.0, $points[1]['latitude']);
        self::assertEqualsWithDelta(0.001, $points[1]['longitude'], 1e-9);
        self::assertSame(10.0, $points[1]['elevation']);
        self::assertEqualsWithDelta($legDistance, $points[1]['distance'], 0.01);
        self::assertEqualsWithDelta($legDistance * 2, $points[1]['totalDistance'], 0.02);
        // ele goes 10.0 -> 5.0 across this leg: a descent, so the raw diff is negative.
        self::assertSame(-5.0, $points[1]['vDistance']);

        // Each leg's trkpt are 60 seconds apart in the fixture.
        self::assertEqualsWithDelta($legDistance / 60 * 3.6, $points[0]['velocity'], 0.01);
        self::assertEqualsWithDelta($legDistance / 60 * 3.6, $points[1]['velocity'], 0.01);
    }

    public function testVelocityIsNullWhenPointsHaveNoTimeData(): void
    {
        $track = new GpsTrack();
        $track->process(__DIR__ . '/../../Fixtures/gpx/missing-creator.gpx');

        $points = $track->getPoints();

        self::assertNull($points[0]['velocity']);
    }

    public function testGetInfoSummarisesDistanceElevationGainAndPointCount(): void
    {
        $track = new GpsTrack();
        $track->process(__DIR__ . '/../../Fixtures/gpx/valid-track.gpx');

        $legDistance = self::haversineMeters(0.0, 0.0, 0.0, 0.001);
        $info = $track->getInfo();

        self::assertSame(2, $info['points']);
        self::assertSame(sprintf('%.02f', $legDistance * 2 / 1000), $info['totalDistance']);
        // Only the 0.0 -> 10.0 climb counts: the second leg is a descent, which the
        // source only counts when its diff is positive.
        self::assertSame('10.00', $info['totalHeight']);
    }

    public function testGetJsonPointsReturnsPointsAsJson(): void
    {
        $track = new GpsTrack();
        $track->process(__DIR__ . '/../../Fixtures/gpx/valid-track.gpx');

        self::assertSame(json_encode($track->getPoints()), $track->getJsonPoints());
    }

    public function testGetRecordedAtReturnsTheFirstTrackPointsTime(): void
    {
        $track = new GpsTrack();
        $track->process(__DIR__ . '/../../Fixtures/gpx/valid-track.gpx');

        self::assertEquals(new \DateTimeImmutable('2026-01-01T10:00:00Z'), $track->getRecordedAt());
    }

    public function testGetRecordedAtReturnsTheFirstRoutePointsTimeWhenThereAreNoTracks(): void
    {
        $track = new GpsTrack();
        $track->process(__DIR__ . '/../../Fixtures/gpx/route-with-time.gpx');

        self::assertEquals(new \DateTimeImmutable('2026-02-01T08:00:00Z'), $track->getRecordedAt());
    }

    public function testGetRecordedAtFallsBackToMetadataTimeWhenPointsHaveNone(): void
    {
        $track = new GpsTrack();
        $track->process(__DIR__ . '/../../Fixtures/gpx/metadata-time-only.gpx');

        self::assertEquals(new \DateTimeImmutable('2026-03-01T09:00:00Z'), $track->getRecordedAt());
    }

    public function testGetRecordedAtIsNullWhenTheFileHasNoTimeDataAtAll(): void
    {
        $track = new GpsTrack();
        $track->process(__DIR__ . '/../../Fixtures/gpx/missing-creator.gpx');

        self::assertNull($track->getRecordedAt());
    }

    public function testGetNameReturnsTheTracksName(): void
    {
        $track = new GpsTrack();
        $track->process(__DIR__ . '/../../Fixtures/gpx/valid-track.gpx');

        self::assertSame('Fixture Track', $track->getName());
    }

    public function testGetNameReturnsTheRoutesNameWhenThereAreNoTracks(): void
    {
        $track = new GpsTrack();
        $track->process(__DIR__ . '/../../Fixtures/gpx/route-with-time.gpx');

        self::assertSame('Fixture Route', $track->getName());
    }

    public function testGetNameIsNullWhenTheFileHasNoName(): void
    {
        $track = new GpsTrack();
        $track->process(__DIR__ . '/../../Fixtures/gpx/valid-track-no-name.gpx');

        self::assertNull($track->getName());
    }

    public function testHasTrackPointsIsTrueForAFileWithTrkpt(): void
    {
        $track = new GpsTrack();
        $track->process(__DIR__ . '/../../Fixtures/gpx/valid-track.gpx');

        self::assertTrue($track->hasTrackPoints());
    }

    public function testHasTrackPointsIsFalseForARouteOnlyFile(): void
    {
        $track = new GpsTrack();
        $track->process(__DIR__ . '/../../Fixtures/gpx/route-with-time.gpx');

        self::assertFalse($track->hasTrackPoints());
    }

    public function testHasVelocityDataIsTrueWhenPointsHaveTimestamps(): void
    {
        $track = new GpsTrack();
        $track->process(__DIR__ . '/../../Fixtures/gpx/valid-track.gpx');

        self::assertTrue($track->hasVelocityData());
    }

    public function testHasVelocityDataIsFalseWhenTrkptHaveNoTimestamps(): void
    {
        $track = new GpsTrack();
        $track->process(__DIR__ . '/../../Fixtures/gpx/valid-track-no-time.gpx');

        self::assertFalse($track->hasVelocityData());
    }

    public function testProcessOnlyUsesTheFirstTrksegWhenThereAreMultiple(): void
    {
        $track = new GpsTrack();
        $track->process(__DIR__ . '/../../Fixtures/gpx/valid-track-multi-segment.gpx');

        $points = $track->getPoints();

        // Same as the single-segment valid-track.gpx fixture: the second <trkseg>'s 2 points
        // (a separate, disconnected leg) are ignored entirely.
        self::assertCount(2, $points);
        self::assertSame(0.0, $points[0]['latitude']);
        self::assertSame(10.0, $points[0]['vDistance']);

        self::assertEquals(new \DateTimeImmutable('2026-01-01T10:00:00Z'), $track->getRecordedAt());
    }
}
