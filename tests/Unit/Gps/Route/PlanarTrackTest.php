<?php

namespace App\Tests\Unit\Gps\Route;

use App\Gps\Route\PlanarTrack;
use App\Gps\Route\RoutePoint;
use PHPUnit\Framework\TestCase;

class PlanarTrackTest extends TestCase
{
    // 0.01 degrees of longitude at the equator is ~1111.95 m.
    private const DEG = 111194.93;

    public function testLengthIsTheSumOfSegmentDistances(): void
    {
        $track = RouteTestHelper::track([[0.0, 0.0], [0.0, 0.005], [0.0, 0.01]]);

        self::assertSame(2, $track->segmentCount());
        self::assertEqualsWithDelta(0.01 * self::DEG, $track->length(), 1.0);
        self::assertEqualsWithDelta(0.005 * self::DEG, $track->segmentLength(0), 1.0);
    }

    public function testConsecutiveDuplicatePointsAreDropped(): void
    {
        $track = RouteTestHelper::track([[0.0, 0.0], [0.0, 0.0], [0.0, 0.005], [0.0, 0.005], [0.0, 0.01]]);

        self::assertSame(2, $track->segmentCount());
        self::assertEqualsWithDelta(0.01 * self::DEG, $track->length(), 1.0);
    }

    public function testFewerThanTwoDistinctPointsIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RouteTestHelper::track([[0.0, 0.0], [0.0, 0.0]]);
    }

    public function testPointAtInterpolatesLatLonAndElevation(): void
    {
        $track = new PlanarTrack([new RoutePoint(0.0, 0.0, 0.0), new RoutePoint(0.0, 0.01, 100.0)], 0.0);

        $point = $track->pointAt($track->length() / 2);

        self::assertEqualsWithDelta(0.005, $point->lon, 1e-6);
        self::assertEqualsWithDelta(50.0, $point->ele, 0.01);
    }

    public function testPositionAtMapsSegmentFractionToDistanceAlongTheTrack(): void
    {
        $track = RouteTestHelper::track([[0.0, 0.0], [0.0, 0.004], [0.0, 0.01]]);

        self::assertEqualsWithDelta(0.004 * self::DEG + 0.5 * 0.006 * self::DEG, $track->positionAt(1, 0.5), 1.0);
    }

    public function testSliceIncludesInterpolatedEndsAndInnerPointsOnly(): void
    {
        $track = RouteTestHelper::track([[0.0, 0.0], [0.0, 0.004], [0.0, 0.008], [0.0, 0.01]]);

        $slice = $track->slice(0.002 * self::DEG, 0.009 * self::DEG);

        self::assertCount(4, $slice); // start, the two inner points, end
        self::assertEqualsWithDelta(0.002, $slice[0]->lon, 1e-6);
        self::assertEqualsWithDelta(0.004, $slice[1]->lon, 1e-9);
        self::assertEqualsWithDelta(0.008, $slice[2]->lon, 1e-9);
        self::assertEqualsWithDelta(0.009, $slice[3]->lon, 1e-6);
    }
}
