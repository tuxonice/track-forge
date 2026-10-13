<?php

namespace App\Tests\Unit\Gps\Route;

use App\Gps\Route\CrossingDetector;
use App\Gps\Route\Geo;
use App\Gps\Route\PlanarTrack;
use App\Gps\Route\RouteFinder;
use App\Gps\Route\RoutePoint;
use App\Gps\Route\SuggestedRoute;
use App\Gps\Route\TrackGraph;
use App\Gps\Route\TrackGraphBuilder;
use PHPUnit\Framework\TestCase;

class RouteFinderTest extends TestCase
{
    private const DEG = 111194.93;

    /** @param list<PlanarTrack> $tracks */
    private function graph(array $tracks): TrackGraph
    {
        return (new TrackGraphBuilder())->build($tracks, (new CrossingDetector(25.0))->detect($tracks));
    }

    /** @return list<PlanarTrack> */
    private function xTracks(): array
    {
        return [
            RouteTestHelper::track([[0.0, 0.0], [0.0, 0.01]]),         // A: 0.004 | 0.006 split
            RouteTestHelper::track([[-0.003, 0.004], [0.005, 0.004]]), // B: 0.003 | 0.005 split
        ];
    }

    public function testAnXGivesFourRoutesRankedByDistanceLongestFirst(): void
    {
        $routes = (new RouteFinder(5, 100000))->find($this->graph($this->xTracks()));

        self::assertCount(4, $routes);
        // Longest piece of A (0.006) + longest piece of B (0.005), then 0.006+0.003, 0.004+0.005, 0.004+0.003.
        $expected = [0.011, 0.009, 0.009, 0.007];
        foreach ($routes as $i => $route) {
            self::assertEqualsWithDelta($expected[$i] * self::DEG, $route->distance, 2.0);
            self::assertSame([0, 1], $route->trackIndexes);
        }
        self::assertGreaterThanOrEqual($routes[1]->distance, $routes[0]->distance);
        self::assertGreaterThanOrEqual($routes[2]->distance, $routes[1]->distance);
    }

    public function testResultsAreLimitedToTheConfiguredMaximum(): void
    {
        $routes = (new RouteFinder(2, 100000))->find($this->graph($this->xTracks()));

        self::assertCount(2, $routes);
    }

    public function testEveryRouteStartsAndEndsAtATrackEndpointAndRidesEveryTrack(): void
    {
        $tracks = $this->xTracks();
        $routes = (new RouteFinder(5, 100000))->find($this->graph($tracks));

        $endpoints = [];
        foreach ($tracks as $track) {
            $endpoints[] = $track->pointAt(0.0);
            $endpoints[] = $track->pointAt($track->length());
        }
        foreach ($routes as $route) {
            $first = $route->points[0];
            $last = $route->points[count($route->points) - 1];
            self::assertTrue($this->isOneOf($first, $endpoints));
            self::assertTrue($this->isOneOf($last, $endpoints));
            self::assertSame([0, 1], $route->trackIndexes);
        }
    }

    public function testTracksThatNeverCrossGiveNoRoutes(): void
    {
        $tracks = [
            RouteTestHelper::track([[0.0, 0.0], [0.0, 0.01]]),
            RouteTestHelper::track([[0.01, 0.0], [0.01, 0.01]]),
        ];

        self::assertSame([], (new RouteFinder(5, 100000))->find($this->graph($tracks)));
    }

    public function testThreeMutuallyCrossingTracksGiveRoutesThatRideAllThree(): void
    {
        $tracks = [
            RouteTestHelper::track([[0.0, -0.002], [0.0, 0.012]]),
            RouteTestHelper::track([[-0.002, -0.001], [0.012, 0.0055]]),
            RouteTestHelper::track([[-0.002, 0.011], [0.012, 0.0045]]),
        ];

        $routes = (new RouteFinder(5, 100000))->find($this->graph($tracks));

        self::assertNotSame([], $routes);
        foreach ($routes as $route) {
            self::assertSame([0, 1, 2], $route->trackIndexes);
        }
        for ($i = 1; $i < count($routes); $i++) {
            self::assertGreaterThanOrEqual($routes[$i]->distance, $routes[$i - 1]->distance);
        }
    }

    public function testTheStepCapStopsTheSearchWithoutAnError(): void
    {
        $routes = (new RouteFinder(5, 1))->find($this->graph($this->xTracks()));

        self::assertSame([], $routes);
    }

    public function testTwoIdenticalTracksGiveTheTrackOnceAndNeverRideItTwice(): void
    {
        $points = [[0.0, 0.0], [0.0, 0.002], [0.0, 0.004], [0.0, 0.006], [0.0, 0.008], [0.0, 0.01]];
        $tracks = [RouteTestHelper::track($points), RouteTestHelper::track($points)];

        $routes = (new RouteFinder(5, 100000))->find($this->graph($tracks));

        self::assertNotSame([], $routes);
        foreach ($routes as $route) {
            self::assertSame([0, 1], $route->trackIndexes);
            self::assertLessThanOrEqual($tracks[0]->length() + 1.0, $route->distance);
        }
        $this->assertNoPhysicalDuplicates($routes);
    }

    /**
     * The same ~10 km route recorded several times with GPS noise: a dense graph of many
     * crossings. The search must finish within its step cap without running out of memory.
     */
    public function testRepeatedRecordingsOfOneRouteFinishWithinAMemoryBound(): void
    {
        mt_srand(42);
        $base = RouteTestHelper::dense([[48.0, 11.0], [48.0, 11.05], [48.03, 11.05], [48.03, 11.1]], 5.0);
        $tracks = [];
        for ($k = 0; $k < 6; $k++) {
            $phaseLat = mt_rand() / mt_getrandmax() * 6.28;
            $phaseLon = mt_rand() / mt_getrandmax() * 6.28;
            $noisy = [];
            foreach ($base as $i => $p) {
                $noisy[] = new RoutePoint(
                    $p->lat + 20.0 / 111195 * sin($i * 0.03 + $phaseLat),
                    $p->lon + 20.0 / 74000 * sin($i * 0.021 + $phaseLon)
                );
            }
            $tracks[] = new PlanarTrack($noisy, 48.0);
        }
        $graph = $this->graph($tracks);

        memory_reset_peak_usage();
        $baseline = memory_get_usage();
        $routes = (new RouteFinder(5, 100000))->find($graph);

        self::assertLessThan(64 * 1024 * 1024, memory_get_peak_usage() - $baseline);
        self::assertLessThanOrEqual(5, count($routes));
        $length = $tracks[0]->length();
        foreach ($routes as $route) {
            // Every recording is the same corridor, so no sensible route is much longer than one of them.
            self::assertLessThan(1.5 * $length, $route->distance);
        }
    }

    /**
     * Two tracks leave the same trailhead, run 2 km together, then A turns north and B south.
     *
     * @return list<PlanarTrack>
     */
    private function sharedTrailhead(): array
    {
        return [
            new PlanarTrack(RouteTestHelper::dense([[0.0, 0.0], [0.0, 0.018], [0.009, 0.018]]), 0.0),
            new PlanarTrack(RouteTestHelper::dense([[0.0, 0.0], [0.0, 0.018], [-0.009, 0.018]]), 0.0),
        ];
    }

    public function testASharedTrailheadIsNeverRiddenTwice(): void
    {
        $tracks = $this->sharedTrailhead();
        $shared = 0.018 * self::DEG;

        $routes = (new RouteFinder(5, 100000))->find($this->graph($tracks));

        self::assertNotSame([], $routes);
        $limit = $tracks[0]->length() + $tracks[1]->length() - $shared;
        foreach ($routes as $route) {
            self::assertLessThanOrEqual($limit + 5.0, $route->distance, 'a route rides the shared stretch twice');
        }
        $this->assertNoPhysicalDuplicates($routes);

        // Ranked first: the trailhead out to either branch end (3 km); the branch-to-branch switch (2 km) is offered.
        self::assertEqualsWithDelta(0.027 * self::DEG, $routes[0]->distance, 5.0);
        self::assertTrue($this->hasRoute($routes, [0.009, 0.018], [-0.009, 0.018], 0.018 * self::DEG));
    }

    /**
     * Two tracks come from the west (A from the north-west, B from the south-west), share a 2 km
     * middle stretch, then split again (A to the north-east, B to the south-east).
     *
     * @return list<PlanarTrack>
     */
    private function sharedMiddle(): array
    {
        return [
            new PlanarTrack(RouteTestHelper::dense([[0.009, 0.0], [0.0, 0.005], [0.0, 0.023], [0.009, 0.028]]), 0.0),
            new PlanarTrack(RouteTestHelper::dense([[-0.009, 0.0], [0.0, 0.005], [0.0, 0.023], [-0.009, 0.028]]), 0.0),
        ];
    }

    public function testASharedMiddleStretchIsNeverRiddenTwiceAndTheSwitchRoutesComeFirst(): void
    {
        $tracks = $this->sharedMiddle();
        $shared = 0.018 * self::DEG;

        $routes = (new RouteFinder(5, 100000))->find($this->graph($tracks));

        self::assertNotSame([], $routes);
        $limit = $tracks[0]->length() + $tracks[1]->length() - $shared;
        foreach ($routes as $route) {
            self::assertLessThanOrEqual($limit + 5.0, $route->distance, 'a route rides the shared stretch twice');
        }
        $this->assertNoPhysicalDuplicates($routes);

        // Nothing beats riding in on one track, the shared stretch once, and out on either track.
        self::assertEqualsWithDelta($tracks[0]->length(), $routes[0]->distance, 5.0);
        // Both X-switches (in on one track, out on the other) are offered.
        self::assertTrue($this->hasRoute($routes, [0.009, 0.0], [-0.009, 0.028], $tracks[0]->length()));
        self::assertTrue($this->hasRoute($routes, [-0.009, 0.0], [0.009, 0.028], $tracks[0]->length()));
    }

    /**
     * @param list<SuggestedRoute> $routes
     * @param array{0: float, 1: float} $one
     * @param array{0: float, 1: float} $other
     */
    private function hasRoute(array $routes, array $one, array $other, float $distance): bool
    {
        foreach ($routes as $route) {
            $ends = [$route->points[0], $route->points[count($route->points) - 1]];
            $a = new RoutePoint($one[0], $one[1]);
            $b = new RoutePoint($other[0], $other[1]);
            $matches = (Geo::distance($ends[0], $a) < 5.0 && Geo::distance($ends[1], $b) < 5.0)
                || (Geo::distance($ends[0], $b) < 5.0 && Geo::distance($ends[1], $a) < 5.0);
            if ($matches && abs($route->distance - $distance) < 5.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * No two routes trace the same line: same ends (either way round), same length and same middle.
     *
     * @param list<SuggestedRoute> $routes
     */
    private function assertNoPhysicalDuplicates(array $routes): void
    {
        foreach ($routes as $i => $x) {
            foreach ($routes as $j => $y) {
                if ($j <= $i) {
                    continue;
                }
                $xEnds = [$x->points[0], $x->points[count($x->points) - 1]];
                $yEnds = [$y->points[0], $y->points[count($y->points) - 1]];
                $sameEnds = (Geo::distance($xEnds[0], $yEnds[0]) < 2.0 && Geo::distance($xEnds[1], $yEnds[1]) < 2.0)
                    || (Geo::distance($xEnds[0], $yEnds[1]) < 2.0 && Geo::distance($xEnds[1], $yEnds[0]) < 2.0);
                self::assertFalse(
                    $sameEnds && abs($x->distance - $y->distance) < 2.0,
                    sprintf('routes #%d and #%d are the same line', $i + 1, $j + 1)
                );
            }
        }
    }

    public function testElevationGainSumsOnlyTheClimbs(): void
    {
        $route = new SuggestedRoute([
            new RoutePoint(0.0, 0.0, 10.0),
            new RoutePoint(0.0, 0.001, 25.0),
            new RoutePoint(0.0, 0.002, 20.0),
            new RoutePoint(0.0, 0.003, 30.0),
        ], 333.0, [0]);

        self::assertSame(25.0, $route->elevationGain());
    }

    /**
     * @param list<RoutePoint> $candidates
     */
    private function isOneOf(RoutePoint $point, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (abs($candidate->lat - $point->lat) < 1e-6 && abs($candidate->lon - $point->lon) < 1e-6) {
                return true;
            }
        }

        return false;
    }
}
