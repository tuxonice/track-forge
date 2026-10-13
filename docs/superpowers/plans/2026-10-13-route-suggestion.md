# Route Suggestion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user select 2+ saved tracks, see up to 5 ranked suggested routes stitched together at the tracks' crossing points, and save one as a new track.

**Architecture:** A pure-PHP geometry pipeline in `src/Gps/Route/` (`PlanarTrack` → `CrossingDetector` → `TrackGraphBuilder` → `RouteFinder`) turns point lists into ranked `SuggestedRoute`s. `RouteSuggestionService` loads stored GPX through `GpsTrack` and runs the pipeline. A new `RouteSuggestionController` renders suggestions (POST, localized) and saves one (POST, unprefixed) via `GpxExporter` + `FileUploaderService`, creating a normal `Track`.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine/SQLite, `sibyx/phpgpx` 1.3.0, Twig, Leaflet (already used by the track view), PHPUnit via `vendor/bin/simple-phpunit`.

**Spec:** `docs/superpowers/specs/2026-10-13-route-suggestion-design.md`

## Global Constraints

- Tolerance default **25 m**; max suggestions **5**; max tracks **6**; search step cap **100000** (spec "Configuration").
- Config values are env-driven: `ROUTE_CROSSING_TOLERANCE_M`, `ROUTE_MAX_SUGGESTIONS`, `ROUTE_MAX_TRACKS`, `ROUTE_SEARCH_STEP_CAP`, bound in `config/services.yaml`, documented in `.env.dist`. Because deployers' existing `.env` files will not have them, also give each a default via a `env(NAME)` parameter so the app still boots.
- Routes start and end only at a selected track's start/end; no edge reused; every selected track must contribute at least one piece; rank by distance, longest first.
- Proximity crossings (not true intersections) are joined by a straight connector.
- `src/Gps/Route/*` has no Symfony/Doctrine dependencies.
- Save endpoint never accepts coordinates: it re-computes suggestions server-side from `trackKeys[]` and a route index.
- `/routes/suggest` is localized (`['en' => '/en/routes/suggest', 'pt' => '/pt/rotas/sugerir']`); `/routes/save` is single and unprefixed; its flash messages use `User::locale`.
- Translations required in both `translations/messages.en.yaml` and `translations/messages.pt.yaml`.
- Suggested tracks have no timestamps (no velocity chart); elevation is copied from the source points.
- Code style: PSR12 (`vendor/bin/phpcs`), PHPStan level 6 (`vendor/bin/phpstan analyse`) must pass; both run in CI. Typed array docblocks (`list<...>`) are required for phpstan.
- Run commands from the repo root. In the Docker flow prefix with `docker compose exec -u www-data app`. Tests: `vendor/bin/simple-phpunit <path>`.

## Review Focus

1. A track with consecutive duplicate points (stationary recording) must not crash geometry or create zero-length segments (Task 1 test).
2. Two selected tracks that are the same route (a duplicate upload) must produce a finite result, not a hang or exception (Task 4 test).
3. A track that starts exactly on another track (a T-junction) must connect through its endpoint rather than leave an orphan node (Task 3 test).
4. Route-only (`<rte>`) GPX files must be usable as inputs (Task 1 test).
5. The same track key submitted twice, or a track over the point cap, must give a clear message, not a crash or a misleading result (Task 6 and Task 7 tests).

---

## File Structure

Create:
- `src/Gps/Route/RoutePoint.php` — immutable lat/lon/ele value.
- `src/Gps/Route/Geo.php` — haversine distance + earth radius constant.
- `src/Gps/Route/PlanarTrack.php` — a track as points + planar x/y + cumulative distance; interpolation and slicing by distance.
- `src/Gps/Route/Crossing.php` — a detected crossing (value object).
- `src/Gps/Route/CrossingDetector.php` — crossings between track pairs.
- `src/Gps/Route/RouteNode.php`, `RouteEdge.php`, `TrackGraph.php` — graph model.
- `src/Gps/Route/TrackGraphBuilder.php` — builds the graph from tracks + crossings.
- `src/Gps/Route/SuggestedRoute.php` — a finished route (points, distance, tracks used, elevation gain).
- `src/Gps/Route/RouteFinder.php` — DFS enumeration + ranking.
- `src/Gps/Route/GpxExporter.php` — route → GPX XML string.
- `src/Service/RouteSuggestionService.php` — loads files, runs pipeline, enforces limits.
- `src/Exception/RouteSuggestionException.php` — translation-key exception.
- `src/Controller/RouteSuggestionController.php` — suggest + save endpoints.
- `templates/Default/route-suggestions.html.twig` — results page.
- Tests: `tests/Unit/Gps/Route/{RouteTestHelper,PlanarTrackTest,CrossingDetectorTest,TrackGraphBuilderTest,RouteFinderTest,GpxExporterTest}.php`, `tests/Unit/Service/RouteSuggestionServiceTest.php`, `tests/Functional/RouteSuggestionControllerTest.php`.
- Fixtures: `tests/Fixtures/gpx/{crossing-a,crossing-b,no-crossing}.gpx`.

Modify:
- `src/Gps/GpsTrack.php` — add `getRawPoints()`.
- `src/Service/FileUploaderService.php` — add `storeContents()`.
- `config/services.yaml`, `.env.dist` — route parameters.
- `templates/Default/home.html.twig` — selection checkboxes + suggest button.
- `translations/messages.{en,pt}.yaml` — new keys.
- `tests/Functional/WebTestCase.php` — helper to persist a track backed by a fixture file.
- `tests/Unit/Gps/GpsTrackTest.php`, `tests/Functional/HomeControllerTest.php` — new cases.
- `CLAUDE.md` — document the feature.

---

### Task 1: Geometry primitives and `GpsTrack::getRawPoints()`

**Files:**
- Create: `src/Gps/Route/RoutePoint.php`, `src/Gps/Route/Geo.php`, `src/Gps/Route/PlanarTrack.php`
- Create: `tests/Unit/Gps/Route/RouteTestHelper.php`, `tests/Unit/Gps/Route/PlanarTrackTest.php`
- Modify: `src/Gps/GpsTrack.php`, `tests/Unit/Gps/GpsTrackTest.php`

**Interfaces:**
- Produces:
  - `RoutePoint(float $lat, float $lon, float $ele = 0.0)` (`final readonly`, public props).
  - `Geo::EARTH_RADIUS` (float const, metres), `Geo::distance(RoutePoint $start, RoutePoint $end): float` (metres).
  - `PlanarTrack::__construct(list<RoutePoint> $points, float $originLat)` (drops consecutive duplicate points; throws `\InvalidArgumentException` when fewer than 2 distinct points remain), `segmentCount(): int`, `length(): float`, `segmentLength(int $segment): float`, `x(int $i): float`, `y(int $i): float`, `positionAt(int $segment, float $t): float`, `pointAt(float $distance): RoutePoint`, `slice(float $from, float $to): list<RoutePoint>`.
  - `GpsTrack::getRawPoints(): list<RoutePoint>` (call after `process()`).
  - Test helper `RouteTestHelper::track(array $latLon, float $originLat = 0.0): PlanarTrack` where `$latLon` is `list<array{0: float, 1: float}>` of `[lat, lon]`.

- [ ] **Step 1: Write the helper and failing tests**

`tests/Unit/Gps/Route/RouteTestHelper.php`:
```php
<?php

namespace App\Tests\Unit\Gps\Route;

use App\Gps\Route\PlanarTrack;
use App\Gps\Route\RoutePoint;

final class RouteTestHelper
{
    /** @param list<array{0: float, 1: float}> $latLon [lat, lon] pairs */
    public static function track(array $latLon, float $originLat = 0.0): PlanarTrack
    {
        return new PlanarTrack(
            array_map(static fn (array $p): RoutePoint => new RoutePoint($p[0], $p[1]), $latLon),
            $originLat
        );
    }
}
```

`tests/Unit/Gps/Route/PlanarTrackTest.php`:
```php
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
```

Append to `tests/Unit/Gps/GpsTrackTest.php` (inside the class, before the final `}`):
```php
    public function testGetRawPointsReturnsEveryTrackPointIncludingTheLastOne(): void
    {
        $track = new GpsTrack();
        $track->process(__DIR__ . '/../../Fixtures/gpx/valid-track.gpx');

        $points = $track->getRawPoints();

        // getPoints() drops the last point (it emits one diff per following point); raw points keep all 3.
        self::assertCount(3, $points);
        self::assertSame(0.0, $points[0]->lat);
        self::assertSame(0.002, $points[2]->lon);
        self::assertSame(5.0, $points[2]->ele);
    }

    public function testGetRawPointsFallsBackToTheFirstRouteForRouteOnlyFiles(): void
    {
        $track = new GpsTrack();
        $track->process(__DIR__ . '/../../Fixtures/gpx/route-with-time.gpx');

        $points = $track->getRawPoints();

        self::assertCount(2, $points);
        self::assertSame(0.001, $points[1]->lon);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/simple-phpunit tests/Unit/Gps`
Expected: FAIL (`Class "App\Gps\Route\PlanarTrack" not found` / `Call to undefined method getRawPoints`).

- [ ] **Step 3: Implement**

`src/Gps/Route/RoutePoint.php`:
```php
<?php

namespace App\Gps\Route;

final readonly class RoutePoint
{
    public function __construct(
        public float $lat,
        public float $lon,
        public float $ele = 0.0,
    ) {
    }
}
```

`src/Gps/Route/Geo.php`:
```php
<?php

namespace App\Gps\Route;

final class Geo
{
    public const EARTH_RADIUS = 6371e3;

    // From https://www.movable-type.co.uk/scripts/latlong.html (same formula as GpsTrack).
    public static function distance(RoutePoint $start, RoutePoint $end): float
    {
        $fi1 = deg2rad($start->lat);
        $fi2 = deg2rad($end->lat);
        $deltaFi = deg2rad($end->lat - $start->lat);
        $deltaLambda = deg2rad($end->lon - $start->lon);

        $a = sin($deltaFi / 2) ** 2 + cos($fi1) * cos($fi2) * sin($deltaLambda / 2) ** 2;

        return self::EARTH_RADIUS * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
```

`src/Gps/Route/PlanarTrack.php`:
```php
<?php

namespace App\Gps\Route;

/**
 * A track as a polyline: its points, their planar metre coordinates (equirectangular around a
 * shared origin latitude, good enough for the few-km areas tracks cover) and the cumulative
 * haversine distance at each point. A "position" is a distance in metres along the track.
 */
final class PlanarTrack
{
    /** @var list<RoutePoint> */
    private array $points = [];
    /** @var list<float> */
    private array $xs = [];
    /** @var list<float> */
    private array $ys = [];
    /** @var list<float> */
    private array $cumulative = [];

    /** @param list<RoutePoint> $points */
    public function __construct(array $points, float $originLat)
    {
        $cosLat = cos(deg2rad($originLat));

        foreach ($points as $point) {
            $last = $this->points[count($this->points) - 1] ?? null;
            if ($last !== null && $last->lat === $point->lat && $last->lon === $point->lon) {
                continue;
            }

            $this->cumulative[] = $last === null
                ? 0.0
                : $this->cumulative[count($this->cumulative) - 1] + Geo::distance($last, $point);
            $this->xs[] = deg2rad($point->lon) * $cosLat * Geo::EARTH_RADIUS;
            $this->ys[] = deg2rad($point->lat) * Geo::EARTH_RADIUS;
            $this->points[] = $point;
        }

        if (count($this->points) < 2) {
            throw new \InvalidArgumentException('A track needs at least two distinct points');
        }
    }

    public function segmentCount(): int
    {
        return count($this->points) - 1;
    }

    public function length(): float
    {
        return $this->cumulative[count($this->cumulative) - 1];
    }

    public function segmentLength(int $segment): float
    {
        return $this->cumulative[$segment + 1] - $this->cumulative[$segment];
    }

    public function x(int $i): float
    {
        return $this->xs[$i];
    }

    public function y(int $i): float
    {
        return $this->ys[$i];
    }

    /** Distance along the track of the point $t (0..1) of the way along $segment. */
    public function positionAt(int $segment, float $t): float
    {
        return $this->cumulative[$segment] + $t * $this->segmentLength($segment);
    }

    public function pointAt(float $distance): RoutePoint
    {
        $distance = min($this->length(), max(0.0, $distance));

        return $this->interpolate($this->segmentAt($distance), $distance);
    }

    /**
     * The points between two distances: an interpolated start, every original point strictly
     * between them, an interpolated end.
     *
     * @return list<RoutePoint>
     */
    public function slice(float $from, float $to): array
    {
        $from = max(0.0, $from);
        $to = min($this->length(), $to);

        $result = [$this->pointAt($from)];
        foreach ($this->points as $i => $point) {
            if ($this->cumulative[$i] > $from && $this->cumulative[$i] < $to) {
                $result[] = $point;
            }
        }
        $result[] = $this->pointAt($to);

        return $result;
    }

    /** The last segment whose start is at or before $distance. */
    private function segmentAt(float $distance): int
    {
        $low = 0;
        $high = $this->segmentCount() - 1;
        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            if ($this->cumulative[$mid] <= $distance) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $low;
    }

    private function interpolate(int $segment, float $distance): RoutePoint
    {
        $start = $this->points[$segment];
        $end = $this->points[$segment + 1];
        $span = $this->segmentLength($segment);
        $t = $span > 0.0 ? min(1.0, max(0.0, ($distance - $this->cumulative[$segment]) / $span)) : 0.0;

        return new RoutePoint(
            $start->lat + ($end->lat - $start->lat) * $t,
            $start->lon + ($end->lon - $start->lon) * $t,
            $start->ele + ($end->ele - $start->ele) * $t,
        );
    }
}
```

In `src/Gps/GpsTrack.php`: add `use App\Gps\Route\RoutePoint;` to the imports and this method after `hasTrackPoints()`:
```php
    /** Every point of the first track segment (or, for route-only files, of the first route) as
     * plain lat/lon/elevation values. Unlike getPoints() it keeps the last point, which the
     * route-suggestion geometry needs. Call after process(). Missing elevations count as 0. */
    /** @return list<RoutePoint> */
    public function getRawPoints(): array
    {
        $segment = $this->gpxFile->tracks[0]->segments[0] ?? null;
        $points = $segment !== null && count($segment->points) > 0
            ? $segment->points
            : ($this->gpxFile->routes[0]->points ?? []);

        return array_map(
            static fn (Point $point): RoutePoint => new RoutePoint(
                (float) $point->latitude,
                (float) $point->longitude,
                (float) ($point->elevation ?? 0.0),
            ),
            array_values($points)
        );
    }
```
(Merge the two docblocks into one: description first, then `@return list<RoutePoint>`.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/simple-phpunit tests/Unit/Gps && vendor/bin/phpcs && vendor/bin/phpstan analyse`
Expected: all PASS / clean.

- [ ] **Step 5: Commit**

```bash
git add src/Gps tests/Unit/Gps
git commit -m "feat(route): add planar track geometry and GpsTrack::getRawPoints"
```

---

### Task 2: `CrossingDetector`

**Files:**
- Create: `src/Gps/Route/Crossing.php`, `src/Gps/Route/CrossingDetector.php`
- Test: `tests/Unit/Gps/Route/CrossingDetectorTest.php`

**Interfaces:**
- Consumes: `PlanarTrack` (Task 1: `segmentCount`, `segmentLength`, `x`, `y`, `positionAt`).
- Produces:
  - `Crossing(int $trackA, int $trackB, float $distanceA, float $distanceB, float $gap)` (`final readonly`; `trackA < trackB` are indexes into the tracks list; distances are metres along each track; `gap` is the straight-line distance between the two locations, 0 for true intersections).
  - `CrossingDetector::__construct(float $routeCrossingToleranceM)`, `detect(list<PlanarTrack> $tracks): list<Crossing>`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Gps/Route/CrossingDetectorTest.php`:
```php
<?php

namespace App\Tests\Unit\Gps\Route;

use App\Gps\Route\CrossingDetector;
use PHPUnit\Framework\TestCase;

class CrossingDetectorTest extends TestCase
{
    private const DEG = 111194.93;

    public function testTwoTracksCrossingInAnXGiveOneZeroGapCrossing(): void
    {
        $a = RouteTestHelper::track([[0.0, 0.0], [0.0, 0.01]]);
        $b = RouteTestHelper::track([[-0.003, 0.004], [0.005, 0.004]]);

        $crossings = (new CrossingDetector(25.0))->detect([$a, $b]);

        self::assertCount(1, $crossings);
        self::assertSame(0, $crossings[0]->trackA);
        self::assertSame(1, $crossings[0]->trackB);
        self::assertEqualsWithDelta(0.004 * self::DEG, $crossings[0]->distanceA, 1.0);
        self::assertEqualsWithDelta(0.003 * self::DEG, $crossings[0]->distanceB, 1.0);
        self::assertEqualsWithDelta(0.0, $crossings[0]->gap, 0.001);
    }

    public function testParallelTracksFartherApartThanTheToleranceGiveNoCrossings(): void
    {
        $a = RouteTestHelper::track([[0.0, 0.0], [0.0, 0.01]]);
        $b = RouteTestHelper::track([[0.001, 0.0], [0.001, 0.01]]); // ~111 m away

        self::assertSame([], (new CrossingDetector(25.0))->detect([$a, $b]));
    }

    public function testTracksThatComeCloseWithoutTouchingGiveAProximityCrossing(): void
    {
        $a = RouteTestHelper::track([[0.0, 0.0], [0.0, 0.01]]);
        // Ends ~11 m short of A, in the middle of it.
        $b = RouteTestHelper::track([[-0.005, 0.005], [-0.0001, 0.005]]);

        $crossings = (new CrossingDetector(25.0))->detect([$a, $b]);

        self::assertCount(1, $crossings);
        self::assertEqualsWithDelta(11.1, $crossings[0]->gap, 0.5);
        self::assertEqualsWithDelta(0.005 * self::DEG, $crossings[0]->distanceA, 1.0);
    }

    public function testAStretchRunTogetherGivesAnEntryAndAnExitCrossingOnly(): void
    {
        $a = RouteTestHelper::track([[0.0, 0.0], [0.0, 0.002], [0.0, 0.004], [0.0, 0.006], [0.0, 0.008], [0.0, 0.01]]);
        // Leaves far away, runs ~11 m beside A from lon 0.002 to 0.008, then leaves again.
        $b = RouteTestHelper::track([
            [0.003, 0.0], [0.0001, 0.002], [0.0001, 0.004], [0.0001, 0.006], [0.0001, 0.008], [0.003, 0.01],
        ]);

        $crossings = (new CrossingDetector(25.0))->detect([$a, $b]);

        self::assertCount(2, $crossings);
        self::assertEqualsWithDelta(0.002 * self::DEG, $crossings[0]->distanceA, 30.0);
        self::assertEqualsWithDelta(0.008 * self::DEG, $crossings[1]->distanceA, 30.0);
    }

    public function testATrackThatCrossesItselfIsNotReportedAgainstItself(): void
    {
        // A figure-eight style self-crossing, but only one track selected.
        $a = RouteTestHelper::track([[0.0, 0.0], [0.004, 0.004], [0.004, 0.0], [0.0, 0.004]]);

        self::assertSame([], (new CrossingDetector(25.0))->detect([$a]));
    }

    public function testEveryPairOfThreeCrossingTracksIsDetected(): void
    {
        $tracks = [
            RouteTestHelper::track([[0.0, -0.002], [0.0, 0.012]]),
            RouteTestHelper::track([[-0.002, -0.001], [0.012, 0.0055]]),
            RouteTestHelper::track([[-0.002, 0.011], [0.012, 0.0045]]),
        ];

        $crossings = (new CrossingDetector(25.0))->detect($tracks);

        $pairs = array_map(static fn ($c): string => $c->trackA . '-' . $c->trackB, $crossings);
        sort($pairs);
        self::assertSame(['0-1', '0-2', '1-2'], $pairs);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/simple-phpunit tests/Unit/Gps/Route/CrossingDetectorTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement**

`src/Gps/Route/Crossing.php`:
```php
<?php

namespace App\Gps\Route;

final readonly class Crossing
{
    public function __construct(
        public int $trackA,
        public int $trackB,
        public float $distanceA,
        public float $distanceB,
        public float $gap,
    ) {
    }
}
```

`src/Gps/Route/CrossingDetector.php`:
```php
<?php

namespace App\Gps\Route;

/**
 * Finds where tracks cross or come within a tolerance of each other. For every segment of track
 * A it finds the closest approach to track B (a spatial grid keeps that fast); consecutive
 * segments in contact form a "run". A short run (a crossing, a touch) becomes one crossing at
 * its closest point; a long run (a stretch ridden together) becomes an entry and an exit crossing.
 */
final class CrossingDetector
{
    /** Runs spanning less than this many tolerances along A count as a single crossing. */
    private const SHORT_RUN_TOLERANCES = 4;

    public function __construct(private readonly float $routeCrossingToleranceM)
    {
    }

    /**
     * @param list<PlanarTrack> $tracks
     * @return list<Crossing>
     */
    public function detect(array $tracks): array
    {
        $crossings = [];
        $count = count($tracks);
        for ($a = 0; $a < $count; $a++) {
            for ($b = $a + 1; $b < $count; $b++) {
                foreach ($this->detectPair($tracks[$a], $tracks[$b], $a, $b) as $crossing) {
                    $crossings[] = $crossing;
                }
            }
        }

        return $crossings;
    }

    /** @return list<Crossing> */
    private function detectPair(PlanarTrack $a, PlanarTrack $b, int $indexA, int $indexB): array
    {
        $cellSize = max(1.0, self::SHORT_RUN_TOLERANCES * $this->routeCrossingToleranceM);
        $grid = $this->buildGrid($b, $cellSize);

        /** @var array<int, array{gap: float, a: float, b: float}> $contacts keyed by segment of A */
        $contacts = [];
        for ($i = 0; $i < $a->segmentCount(); $i++) {
            $best = null;
            foreach ($this->candidates($grid, $a, $i, $cellSize) as $j) {
                [$gap, $t, $u] = $this->closest($a, $i, $b, $j);
                if ($gap <= $this->routeCrossingToleranceM && ($best === null || $gap < $best['gap'])) {
                    $best = ['gap' => $gap, 'a' => $a->positionAt($i, $t), 'b' => $b->positionAt($j, $u)];
                }
            }
            if ($best !== null) {
                $contacts[$i] = $best;
            }
        }

        return $this->crossingsFromContacts($contacts, $a, $indexA, $indexB);
    }

    /**
     * @param array<int, array{gap: float, a: float, b: float}> $contacts
     * @return list<Crossing>
     */
    private function crossingsFromContacts(array $contacts, PlanarTrack $a, int $indexA, int $indexB): array
    {
        /** @var list<list<array{gap: float, a: float, b: float}>> $runs */
        $runs = [];
        $run = [];
        $previousSegment = null;
        $previous = null;
        foreach ($contacts as $segment => $contact) {
            if (
                $previous !== null && $previousSegment !== null
                && ($segment !== $previousSegment + 1
                    || abs($contact['b'] - $previous['b']) > self::SHORT_RUN_TOLERANCES * $this->routeCrossingToleranceM + $a->segmentLength($previousSegment))
            ) {
                $runs[] = $run;
                $run = [];
            }
            $run[] = $contact;
            $previousSegment = $segment;
            $previous = $contact;
        }
        if ($run !== []) {
            $runs[] = $run;
        }

        $crossings = [];
        foreach ($runs as $run) {
            $first = $run[0];
            $last = $run[count($run) - 1];
            if (count($run) === 1 || $last['a'] - $first['a'] < self::SHORT_RUN_TOLERANCES * $this->routeCrossingToleranceM) {
                $closest = $first;
                foreach ($run as $contact) {
                    if ($contact['gap'] < $closest['gap']) {
                        $closest = $contact;
                    }
                }
                $crossings[] = new Crossing($indexA, $indexB, $closest['a'], $closest['b'], $closest['gap']);
                continue;
            }
            $crossings[] = new Crossing($indexA, $indexB, $first['a'], $first['b'], $first['gap']);
            $crossings[] = new Crossing($indexA, $indexB, $last['a'], $last['b'], $last['gap']);
        }

        return $crossings;
    }

    /** @return array<string, list<int>> segment indexes of $track per grid cell */
    private function buildGrid(PlanarTrack $track, float $cellSize): array
    {
        $grid = [];
        for ($j = 0; $j < $track->segmentCount(); $j++) {
            $minX = min($track->x($j), $track->x($j + 1));
            $maxX = max($track->x($j), $track->x($j + 1));
            $minY = min($track->y($j), $track->y($j + 1));
            $maxY = max($track->y($j), $track->y($j + 1));
            for ($cx = (int) floor($minX / $cellSize); $cx <= (int) floor($maxX / $cellSize); $cx++) {
                for ($cy = (int) floor($minY / $cellSize); $cy <= (int) floor($maxY / $cellSize); $cy++) {
                    $grid[$cx . ':' . $cy][] = $j;
                }
            }
        }

        return $grid;
    }

    /**
     * Segments of the gridded track whose cells overlap segment $i of $a expanded by the tolerance.
     *
     * @param array<string, list<int>> $grid
     * @return list<int>
     */
    private function candidates(array $grid, PlanarTrack $a, int $i, float $cellSize): array
    {
        $tolerance = $this->routeCrossingToleranceM;
        $minX = min($a->x($i), $a->x($i + 1)) - $tolerance;
        $maxX = max($a->x($i), $a->x($i + 1)) + $tolerance;
        $minY = min($a->y($i), $a->y($i + 1)) - $tolerance;
        $maxY = max($a->y($i), $a->y($i + 1)) + $tolerance;

        $found = [];
        for ($cx = (int) floor($minX / $cellSize); $cx <= (int) floor($maxX / $cellSize); $cx++) {
            for ($cy = (int) floor($minY / $cellSize); $cy <= (int) floor($maxY / $cellSize); $cy++) {
                foreach ($grid[$cx . ':' . $cy] ?? [] as $j) {
                    $found[$j] = $j;
                }
            }
        }
        ksort($found);

        return array_values($found);
    }

    /**
     * Closest approach between segment $i of $a and segment $j of $b.
     *
     * @return array{0: float, 1: float, 2: float} distance in metres, parameter (0..1) on a's segment, parameter on b's
     */
    private function closest(PlanarTrack $a, int $i, PlanarTrack $b, int $j): array
    {
        $ax = $a->x($i);
        $ay = $a->y($i);
        $rx = $a->x($i + 1) - $ax;
        $ry = $a->y($i + 1) - $ay;
        $bx = $b->x($j);
        $by = $b->y($j);
        $sx = $b->x($j + 1) - $bx;
        $sy = $b->y($j + 1) - $by;

        $denominator = $rx * $sy - $ry * $sx;
        if (abs($denominator) > 1e-9) {
            $qx = $bx - $ax;
            $qy = $by - $ay;
            $t = ($qx * $sy - $qy * $sx) / $denominator;
            $u = ($qx * $ry - $qy * $rx) / $denominator;
            if ($t >= 0.0 && $t <= 1.0 && $u >= 0.0 && $u <= 1.0) {
                return [0.0, $t, $u];
            }
        }

        // No intersection: the closest approach involves an endpoint of one of the segments.
        $best = [INF, 0.0, 0.0];
        foreach ([0.0, 1.0] as $t) {
            $px = $ax + $t * $rx;
            $py = $ay + $t * $ry;
            $u = $this->project($px, $py, $bx, $by, $sx, $sy);
            $gap = hypot($px - ($bx + $u * $sx), $py - ($by + $u * $sy));
            if ($gap < $best[0]) {
                $best = [$gap, $t, $u];
            }
        }
        foreach ([0.0, 1.0] as $u) {
            $px = $bx + $u * $sx;
            $py = $by + $u * $sy;
            $t = $this->project($px, $py, $ax, $ay, $rx, $ry);
            $gap = hypot($px - ($ax + $t * $rx), $py - ($ay + $t * $ry));
            if ($gap < $best[0]) {
                $best = [$gap, $t, $u];
            }
        }

        return $best;
    }

    /** Parameter (clamped to 0..1) of the point on segment (x1,y1)+t(dx,dy) closest to (px,py). */
    private function project(float $px, float $py, float $x1, float $y1, float $dx, float $dy): float
    {
        $lengthSquared = $dx * $dx + $dy * $dy;
        if ($lengthSquared <= 0.0) {
            return 0.0;
        }

        return min(1.0, max(0.0, (($px - $x1) * $dx + ($py - $y1) * $dy) / $lengthSquared));
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/simple-phpunit tests/Unit/Gps/Route/CrossingDetectorTest.php && vendor/bin/phpcs && vendor/bin/phpstan analyse`
Expected: PASS / clean. If `testAStretchRun...` fails on the count, print `$crossings` and check the contact-run split rule in `crossingsFromContacts` rather than loosening the test.

- [ ] **Step 5: Commit**

```bash
git add src/Gps/Route tests/Unit/Gps/Route
git commit -m "feat(route): detect crossings and shared stretches between tracks"
```

---

### Task 3: Graph model and `TrackGraphBuilder`

**Files:**
- Create: `src/Gps/Route/RouteNode.php`, `RouteEdge.php`, `TrackGraph.php`, `TrackGraphBuilder.php`
- Test: `tests/Unit/Gps/Route/TrackGraphBuilderTest.php`

**Interfaces:**
- Consumes: `PlanarTrack` (`length`, `slice`, `pointAt`), `Crossing`, `Geo::distance`, `RoutePoint`.
- Produces:
  - `RouteNode(int $id, int $trackIndex, float $distance, bool $isEndpoint)` (`final readonly`).
  - `RouteEdge(int $id, int $from, int $to, ?int $trackIndex, float $length, list<RoutePoint> $points)` (`final readonly`; `trackIndex` null = connector; `points` run from `from` to `to`), with `otherEnd(int $nodeId): int` and `pointsFrom(int $nodeId): list<RoutePoint>`.
  - `TrackGraph(int $trackCount)` with public `list<RouteNode> $nodes`, `list<RouteEdge> $edges`, `array<int, list<int>> $adjacency`; `addNode(int $trackIndex, float $distance, bool $endpoint): int`, `addEdge(int $from, int $to, ?int $trackIndex, float $length, array $points): void`.
  - `TrackGraphBuilder::build(list<PlanarTrack> $tracks, list<Crossing> $crossings): TrackGraph`. Cuts within 1 m of each other or of a track end are merged/snapped, so a crossing at a track's end reuses that endpoint node.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Gps/Route/TrackGraphBuilderTest.php`:
```php
<?php

namespace App\Tests\Unit\Gps\Route;

use App\Gps\Route\Crossing;
use App\Gps\Route\CrossingDetector;
use App\Gps\Route\TrackGraphBuilder;
use PHPUnit\Framework\TestCase;

class TrackGraphBuilderTest extends TestCase
{
    public function testAnXOfTwoTracksHasSixNodesFourPiecesAndOneConnector(): void
    {
        $a = RouteTestHelper::track([[0.0, 0.0], [0.0, 0.01]]);
        $b = RouteTestHelper::track([[-0.003, 0.004], [0.005, 0.004]]);
        $crossings = (new CrossingDetector(25.0))->detect([$a, $b]);

        $graph = (new TrackGraphBuilder())->build([$a, $b], $crossings);

        // Per track: start, crossing, end.
        self::assertCount(6, $graph->nodes);
        $pieces = array_filter($graph->edges, static fn ($e): bool => $e->trackIndex !== null);
        $connectors = array_filter($graph->edges, static fn ($e): bool => $e->trackIndex === null);
        self::assertCount(4, $pieces);
        self::assertCount(1, $connectors);
        self::assertSame(4, count(array_filter($graph->nodes, static fn ($n): bool => $n->isEndpoint)));
    }

    public function testPieceLengthsSumToTheOriginalTrackLength(): void
    {
        $a = RouteTestHelper::track([[0.0, 0.0], [0.0, 0.01]]);
        $b = RouteTestHelper::track([[-0.003, 0.004], [0.005, 0.004]]);
        $graph = (new TrackGraphBuilder())->build([$a, $b], (new CrossingDetector(25.0))->detect([$a, $b]));

        $lengthA = array_sum(array_map(
            static fn ($e): float => $e->trackIndex === 0 ? $e->length : 0.0,
            $graph->edges
        ));

        self::assertEqualsWithDelta($a->length(), $lengthA, 0.001);
    }

    public function testATJunctionReusesTheEndpointNodeInsteadOfCreatingAnOrphan(): void
    {
        $a = RouteTestHelper::track([[0.0, 0.0], [0.0, 0.01]]);
        $b = RouteTestHelper::track([[0.0, 0.005], [0.005, 0.005]]); // B starts exactly on A
        $graph = (new TrackGraphBuilder())->build([$a, $b], (new CrossingDetector(25.0))->detect([$a, $b]));

        // A: start, junction, end. B: start (snapped, still its own endpoint node), end.
        self::assertCount(5, $graph->nodes);
        $bStart = array_values(array_filter(
            $graph->nodes,
            static fn ($n): bool => $n->trackIndex === 1 && $n->distance === 0.0
        ))[0];
        self::assertTrue($bStart->isEndpoint);
        // That endpoint node is linked to A's junction by a connector.
        $connectorIds = array_filter(
            $graph->adjacency[$bStart->id],
            static fn (int $id): bool => $graph->edges[$id]->trackIndex === null
        );
        self::assertCount(1, $connectorIds);
    }

    public function testCrossingsAtTheSameSpotShareOneNodePerTrack(): void
    {
        $a = RouteTestHelper::track([[0.0, 0.0], [0.0, 0.01]]);
        $b = RouteTestHelper::track([[-0.003, 0.004], [0.005, 0.004]]);
        $near = 0.004 * 111194.93;
        $crossings = [
            new Crossing(0, 1, $near, 333.6, 0.0),
            new Crossing(0, 1, $near + 0.4, 333.6 + 0.4, 0.0), // within the 1 m snap
        ];

        $graph = (new TrackGraphBuilder())->build([$a, $b], $crossings);

        self::assertCount(6, $graph->nodes);
        self::assertCount(1, array_filter($graph->edges, static fn ($e): bool => $e->trackIndex === null));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/simple-phpunit tests/Unit/Gps/Route/TrackGraphBuilderTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement**

`src/Gps/Route/RouteNode.php`:
```php
<?php

namespace App\Gps\Route;

final readonly class RouteNode
{
    public function __construct(
        public int $id,
        public int $trackIndex,
        public float $distance,
        public bool $isEndpoint,
    ) {
    }
}
```

`src/Gps/Route/RouteEdge.php`:
```php
<?php

namespace App\Gps\Route;

/** A piece of one track between two nodes, or (trackIndex null) a connector between two tracks. */
final readonly class RouteEdge
{
    /** @param list<RoutePoint> $points ordered from `from` to `to` */
    public function __construct(
        public int $id,
        public int $from,
        public int $to,
        public ?int $trackIndex,
        public float $length,
        public array $points,
    ) {
    }

    public function otherEnd(int $nodeId): int
    {
        return $nodeId === $this->from ? $this->to : $this->from;
    }

    /** @return list<RoutePoint> the points in the order they are met when entering at $nodeId */
    public function pointsFrom(int $nodeId): array
    {
        return $nodeId === $this->from ? $this->points : array_reverse($this->points);
    }
}
```

`src/Gps/Route/TrackGraph.php`:
```php
<?php

namespace App\Gps\Route;

final class TrackGraph
{
    /** @var list<RouteNode> */
    public array $nodes = [];
    /** @var list<RouteEdge> */
    public array $edges = [];
    /** @var array<int, list<int>> edge ids per node id */
    public array $adjacency = [];

    public function __construct(public readonly int $trackCount)
    {
    }

    public function addNode(int $trackIndex, float $distance, bool $endpoint): int
    {
        $id = count($this->nodes);
        $this->nodes[] = new RouteNode($id, $trackIndex, $distance, $endpoint);
        $this->adjacency[$id] = [];

        return $id;
    }

    /** @param list<RoutePoint> $points ordered from $from to $to */
    public function addEdge(int $from, int $to, ?int $trackIndex, float $length, array $points): void
    {
        $id = count($this->edges);
        $this->edges[] = new RouteEdge($id, $from, $to, $trackIndex, $length, $points);
        $this->adjacency[$from][] = $id;
        $this->adjacency[$to][] = $id;
    }
}
```

`src/Gps/Route/TrackGraphBuilder.php`:
```php
<?php

namespace App\Gps\Route;

/**
 * Splits each track at its crossings. Nodes are locations on a track (its two endpoints and every
 * crossing); edges are the track pieces between consecutive nodes, plus a connector edge for each
 * crossing linking the two tracks' nodes.
 */
final class TrackGraphBuilder
{
    /** Cuts closer than this (metres) are the same node; cuts this close to a track end snap to it. */
    private const SNAP = 1.0;

    /**
     * @param list<PlanarTrack> $tracks
     * @param list<Crossing> $crossings
     */
    public function build(array $tracks, array $crossings): TrackGraph
    {
        /** @var array<int, list<float>> $cuts */
        $cuts = [];
        foreach ($tracks as $index => $track) {
            $raw = [0.0, $track->length()];
            foreach ($crossings as $crossing) {
                if ($crossing->trackA === $index) {
                    $raw[] = $crossing->distanceA;
                }
                if ($crossing->trackB === $index) {
                    $raw[] = $crossing->distanceB;
                }
            }
            $cuts[$index] = $this->mergeCuts($raw, $track->length());
        }

        $graph = new TrackGraph(count($tracks));
        /** @var array<int, array<int, int>> $nodeIds track index => cut index => node id */
        $nodeIds = [];
        foreach ($cuts as $index => $list) {
            foreach ($list as $k => $distance) {
                $nodeIds[$index][$k] = $graph->addNode($index, $distance, $k === 0 || $k === count($list) - 1);
            }
        }

        foreach ($cuts as $index => $list) {
            for ($k = 0; $k + 1 < count($list); $k++) {
                $graph->addEdge(
                    $nodeIds[$index][$k],
                    $nodeIds[$index][$k + 1],
                    $index,
                    $list[$k + 1] - $list[$k],
                    $tracks[$index]->slice($list[$k], $list[$k + 1])
                );
            }
        }

        $linked = [];
        foreach ($crossings as $crossing) {
            $from = $nodeIds[$crossing->trackA][$this->nearestCut($cuts[$crossing->trackA], $crossing->distanceA)];
            $to = $nodeIds[$crossing->trackB][$this->nearestCut($cuts[$crossing->trackB], $crossing->distanceB)];
            $key = min($from, $to) . '-' . max($from, $to);
            if (isset($linked[$key])) {
                continue;
            }
            $linked[$key] = true;

            $fromPoint = $tracks[$crossing->trackA]->pointAt($graph->nodes[$from]->distance);
            $toPoint = $tracks[$crossing->trackB]->pointAt($graph->nodes[$to]->distance);
            $graph->addEdge($from, $to, null, Geo::distance($fromPoint, $toPoint), [$fromPoint, $toPoint]);
        }

        return $graph;
    }

    /**
     * @param list<float> $raw
     * @return list<float> ascending, starting at 0.0 and ending at $length
     */
    private function mergeCuts(array $raw, float $length): array
    {
        sort($raw);
        $merged = [];
        foreach ($raw as $distance) {
            if ($merged !== [] && $distance - $merged[count($merged) - 1] <= self::SNAP) {
                continue;
            }
            $merged[] = $distance;
        }

        $last = count($merged) - 1;
        if ($last > 0 && $length - $merged[$last] <= self::SNAP) {
            $merged[$last] = $length;
        } elseif ($merged[$last] < $length) {
            $merged[] = $length;
        }

        return $merged;
    }

    /** @param list<float> $cuts */
    private function nearestCut(array $cuts, float $distance): int
    {
        $best = 0;
        foreach ($cuts as $k => $cut) {
            if (abs($cut - $distance) < abs($cuts[$best] - $distance)) {
                $best = $k;
            }
        }

        return $best;
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/simple-phpunit tests/Unit/Gps/Route && vendor/bin/phpcs && vendor/bin/phpstan analyse`
Expected: PASS / clean.

- [ ] **Step 5: Commit**

```bash
git add src/Gps/Route tests/Unit/Gps/Route
git commit -m "feat(route): build a node/edge graph from tracks and their crossings"
```

---

### Task 4: `SuggestedRoute` and `RouteFinder`

**Files:**
- Create: `src/Gps/Route/SuggestedRoute.php`, `src/Gps/Route/RouteFinder.php`
- Test: `tests/Unit/Gps/Route/RouteFinderTest.php`

**Interfaces:**
- Consumes: `TrackGraph`, `RouteEdge::otherEnd/pointsFrom` (Task 3).
- Produces:
  - `SuggestedRoute(list<RoutePoint> $points, float $distance, list<int> $trackIndexes)` (`final readonly`; `distance` in metres; `trackIndexes` ascending); `elevationGain(): float` (sum of positive elevation differences between consecutive points, metres).
  - `RouteFinder::__construct(int $routeMaxSuggestions, int $routeSearchStepCap)`, `find(TrackGraph $graph): list<SuggestedRoute>` (longest first, at most `routeMaxSuggestions`).

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Gps/Route/RouteFinderTest.php`:
```php
<?php

namespace App\Tests\Unit\Gps\Route;

use App\Gps\Route\CrossingDetector;
use App\Gps\Route\PlanarTrack;
use App\Gps\Route\RouteFinder;
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

    public function testTwoIdenticalTracksFinishWithAFiniteResult(): void
    {
        $points = [[0.0, 0.0], [0.0, 0.002], [0.0, 0.004], [0.0, 0.006], [0.0, 0.008], [0.0, 0.01]];
        $tracks = [RouteTestHelper::track($points), RouteTestHelper::track($points)];

        $routes = (new RouteFinder(5, 100000))->find($this->graph($tracks));

        self::assertLessThanOrEqual(5, count($routes));
        foreach ($routes as $route) {
            self::assertSame([0, 1], $route->trackIndexes);
        }
    }

    public function testElevationGainSumsOnlyTheClimbs(): void
    {
        $route = new \App\Gps\Route\SuggestedRoute([
            new \App\Gps\Route\RoutePoint(0.0, 0.0, 10.0),
            new \App\Gps\Route\RoutePoint(0.0, 0.001, 25.0),
            new \App\Gps\Route\RoutePoint(0.0, 0.002, 20.0),
            new \App\Gps\Route\RoutePoint(0.0, 0.003, 30.0),
        ], 333.0, [0]);

        self::assertSame(25.0, $route->elevationGain());
    }

    /**
     * @param list<\App\Gps\Route\RoutePoint> $candidates
     */
    private function isOneOf(\App\Gps\Route\RoutePoint $point, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (abs($candidate->lat - $point->lat) < 1e-6 && abs($candidate->lon - $point->lon) < 1e-6) {
                return true;
            }
        }

        return false;
    }
}
```
(Replace the inline `\App\Gps\Route\...` with `use` imports for `RoutePoint` and `SuggestedRoute` at the top of the file when writing it.)

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/simple-phpunit tests/Unit/Gps/Route/RouteFinderTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement**

`src/Gps/Route/SuggestedRoute.php`:
```php
<?php

namespace App\Gps\Route;

final readonly class SuggestedRoute
{
    /**
     * @param list<RoutePoint> $points
     * @param list<int> $trackIndexes indexes of the selected tracks this route rides, ascending
     */
    public function __construct(
        public array $points,
        public float $distance,
        public array $trackIndexes,
    ) {
    }

    public function elevationGain(): float
    {
        $gain = 0.0;
        for ($i = 1; $i < count($this->points); $i++) {
            $difference = $this->points[$i]->ele - $this->points[$i - 1]->ele;
            if ($difference > 0.0) {
                $gain += $difference;
            }
        }

        return $gain;
    }
}
```

`src/Gps/Route/RouteFinder.php`:
```php
<?php

namespace App\Gps\Route;

/**
 * Enumerates routes through a TrackGraph with a depth-first search: start at a track endpoint,
 * never use an edge twice, end at a track endpoint having ridden at least one piece of every
 * track. Results are ranked longest first. The search stops after a fixed number of steps so a
 * pathological graph cannot run away.
 */
final class RouteFinder
{
    private int $steps = 0;

    public function __construct(
        private readonly int $routeMaxSuggestions,
        private readonly int $routeSearchStepCap,
    ) {
    }

    /** @return list<SuggestedRoute> */
    public function find(TrackGraph $graph): array
    {
        $this->steps = 0;
        /** @var list<array{key: string, distance: float, start: int, path: list<int>}> $found */
        $found = [];
        /** @var array<string, true> $seen */
        $seen = [];

        foreach ($graph->nodes as $start) {
            if ($start->isEndpoint) {
                $this->walk($graph, $start->id, $start->id, [], [], [], $found, $seen);
            }
        }

        usort(
            $found,
            static fn (array $x, array $y): int => $y['distance'] <=> $x['distance'] ?: strcmp($x['key'], $y['key'])
        );

        return array_map(
            fn (array $route): SuggestedRoute => $this->build($graph, $route['start'], $route['path']),
            array_slice($found, 0, max(0, $this->routeMaxSuggestions))
        );
    }

    /**
     * @param list<int> $path edge ids ridden so far
     * @param array<int, true> $used
     * @param array<int, int> $tracksUsed track index => pieces ridden
     * @param list<array{key: string, distance: float, start: int, path: list<int>}> $found
     * @param array<string, true> $seen
     */
    private function walk(
        TrackGraph $graph,
        int $startId,
        int $current,
        array $path,
        array $used,
        array $tracksUsed,
        array &$found,
        array &$seen,
    ): void {
        if (++$this->steps > $this->routeSearchStepCap) {
            return;
        }

        if (
            $path !== [] && $current !== $startId
            && $graph->nodes[$current]->isEndpoint
            && count($tracksUsed) === $graph->trackCount
        ) {
            $this->record($graph, $startId, $path, $found, $seen);
        }

        foreach ($graph->adjacency[$current] as $edgeId) {
            if (isset($used[$edgeId])) {
                continue;
            }
            if ($this->steps > $this->routeSearchStepCap) {
                return;
            }

            $edge = $graph->edges[$edgeId];
            $nextUsed = $used;
            $nextUsed[$edgeId] = true;
            $nextTracks = $tracksUsed;
            if ($edge->trackIndex !== null) {
                $nextTracks[$edge->trackIndex] = ($nextTracks[$edge->trackIndex] ?? 0) + 1;
            }

            $this->walk($graph, $startId, $edge->otherEnd($current), [...$path, $edgeId], $nextUsed, $nextTracks, $found, $seen);
        }
    }

    /**
     * @param list<int> $path
     * @param list<array{key: string, distance: float, start: int, path: list<int>}> $found
     * @param array<string, true> $seen
     */
    private function record(TrackGraph $graph, int $startId, array $path, array &$found, array &$seen): void
    {
        // The same route is found from both of its ends; keep the first.
        $forward = implode(',', $path);
        $backward = implode(',', array_reverse($path));
        $key = strcmp($forward, $backward) <= 0 ? $forward : $backward;
        if (isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;

        $distance = 0.0;
        foreach ($path as $edgeId) {
            $distance += $graph->edges[$edgeId]->length;
        }
        $found[] = ['key' => $key, 'distance' => $distance, 'start' => $startId, 'path' => $path];
    }

    /** @param list<int> $path */
    private function build(TrackGraph $graph, int $startId, array $path): SuggestedRoute
    {
        $points = [];
        $distance = 0.0;
        $tracks = [];
        $current = $startId;
        foreach ($path as $edgeId) {
            $edge = $graph->edges[$edgeId];
            foreach ($edge->pointsFrom($current) as $point) {
                $last = $points[count($points) - 1] ?? null;
                if ($last !== null && $last->lat === $point->lat && $last->lon === $point->lon) {
                    continue;
                }
                $points[] = $point;
            }
            $distance += $edge->length;
            if ($edge->trackIndex !== null) {
                $tracks[$edge->trackIndex] = $edge->trackIndex;
            }
            $current = $edge->otherEnd($current);
        }
        ksort($tracks);

        return new SuggestedRoute($points, $distance, array_values($tracks));
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/simple-phpunit tests/Unit/Gps/Route && vendor/bin/phpcs && vendor/bin/phpstan analyse`
Expected: PASS / clean.

- [ ] **Step 5: Commit**

```bash
git add src/Gps/Route tests/Unit/Gps/Route
git commit -m "feat(route): enumerate and rank routes through the track graph"
```

---

### Task 5: `GpxExporter`

**Files:**
- Create: `src/Gps/Route/GpxExporter.php`
- Test: `tests/Unit/Gps/Route/GpxExporterTest.php`

**Interfaces:**
- Consumes: `SuggestedRoute`, `RoutePoint`.
- Produces: `GpxExporter::export(SuggestedRoute $route, string $name): string` — GPX 1.1 XML with one `<trk>`/`<trkseg>`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Gps/Route/GpxExporterTest.php`:
```php
<?php

namespace App\Tests\Unit\Gps\Route;

use App\Gps\GpsTrack;
use App\Gps\Route\GpxExporter;
use App\Gps\Route\RoutePoint;
use App\Gps\Route\SuggestedRoute;
use App\Validator\XmlValidator;
use PHPUnit\Framework\TestCase;

class GpxExporterTest extends TestCase
{
    private function route(): SuggestedRoute
    {
        return new SuggestedRoute([
            new RoutePoint(0.0, 0.0, 5.0),
            new RoutePoint(0.0, 0.001, 15.0),
            new RoutePoint(0.0, 0.002, 10.0),
        ], 222.4, [0, 1]);
    }

    public function testExportedXmlValidatesAgainstTheGpxSchema(): void
    {
        $xml = (new GpxExporter())->export($this->route(), 'Suggested route');

        $schema = (string) file_get_contents(__DIR__ . '/../../../../src/Schemas/gpx.xsd');
        self::assertTrue((new XmlValidator())->isValid($xml, $schema));
    }

    public function testExportedFileParsesBackThroughGpsTrackWithTheSamePointsAndName(): void
    {
        $xml = (new GpxExporter())->export($this->route(), 'Suggested route');
        $path = tempnam(sys_get_temp_dir(), 'gpx');
        self::assertNotFalse($path);
        file_put_contents($path, $xml);

        try {
            $track = new GpsTrack();
            $track->process($path);
        } finally {
            unlink($path);
        }

        self::assertSame('Suggested route', $track->getName());
        $points = $track->getRawPoints();
        self::assertCount(3, $points);
        self::assertSame(0.002, $points[2]->lon);
        self::assertSame(10.0, $points[2]->ele);
        self::assertNull($track->getRecordedAt());
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/simple-phpunit tests/Unit/Gps/Route/GpxExporterTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement**

`src/Gps/Route/GpxExporter.php`:
```php
<?php

namespace App\Gps\Route;

use phpGPX\Models\GpxFile;
use phpGPX\Models\Point;
use phpGPX\Models\Segment;
use phpGPX\Models\Track;

/** Writes a suggested route as a one-track GPX 1.1 document (no timestamps: stitched pieces have no coherent timeline). */
final class GpxExporter
{
    public function export(SuggestedRoute $route, string $name): string
    {
        $segment = new Segment();
        foreach ($route->points as $routePoint) {
            $point = new Point(Point::TRACKPOINT);
            $point->latitude = $routePoint->lat;
            $point->longitude = $routePoint->lon;
            $point->elevation = $routePoint->ele;
            $segment->points[] = $point;
        }

        $track = new Track();
        $track->name = $name;
        $track->segments[] = $segment;

        $gpx = new GpxFile();
        $gpx->creator = 'TrackForge';
        $gpx->tracks[] = $track;

        return (string) $gpx->toXML()->saveXML();
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/simple-phpunit tests/Unit/Gps/Route/GpxExporterTest.php && vendor/bin/phpcs && vendor/bin/phpstan analyse`
Expected: PASS / clean. If phpGPX needs `$track->recalculateStats()` or similar before `toXML()`, add it. Do not change the tests to hide a real serialization problem.

- [ ] **Step 5: Commit**

```bash
git add src/Gps/Route/GpxExporter.php tests/Unit/Gps/Route/GpxExporterTest.php
git commit -m "feat(route): export a suggested route as GPX"
```

---

### Task 6: `RouteSuggestionService`, config and `FileUploaderService::storeContents`

**Files:**
- Create: `src/Exception/RouteSuggestionException.php`, `src/Service/RouteSuggestionService.php`
- Create: `tests/Unit/Service/RouteSuggestionServiceTest.php`
- Create: `tests/Fixtures/gpx/crossing-a.gpx`, `crossing-b.gpx`, `no-crossing.gpx`
- Modify: `src/Service/FileUploaderService.php`, `config/services.yaml`, `.env.dist`
- Test: `tests/Unit/Service/FileUploaderServiceTest.php` (create)

**Interfaces:**
- Consumes: `GpsTrack::process/getRawPoints`, `FileUploaderService::getUserDataPath`, `Track` entity, `CrossingDetector`, `TrackGraphBuilder`, `RouteFinder`, `PlanarTrack`.
- Produces:
  - `RouteSuggestionException(string $translationKey, array<string, string|int> $parameters = [])` with `getParameters()`.
  - `RouteSuggestionService::suggest(list<Track> $tracks): list<SuggestedRoute>`. Throws `RouteSuggestionException` with keys `route.error.too_few_tracks`, `route.error.too_many_tracks` (`%max%`), `route.error.track_file_missing` (`%name%`), `route.error.track_too_large` (`%name%`, `%max%`), `route.error.track_too_short` (`%name%`). An empty list means no valid route.
  - `FileUploaderService::storeContents(User $user, string $contents, string $targetFileName): bool`.
  - Container wiring: `CrossingDetector` ← `float $routeCrossingToleranceM`, `RouteFinder` ← `int $routeMaxSuggestions`, `int $routeSearchStepCap`, `RouteSuggestionService` ← `int $routeMaxTracks`.

- [ ] **Step 1: Write fixtures**

`tests/Fixtures/gpx/crossing-a.gpx` (west→east, crosses B mid-segment; the same geometry as the unit tests' A):
```xml
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="track-forge-tests" xmlns="http://www.topografix.com/GPX/1/1">
  <trk>
    <name>Crossing A</name>
    <trkseg>
      <trkpt lat="0.0000000" lon="0.0000000"><ele>0.0</ele></trkpt>
      <trkpt lat="0.0000000" lon="0.0050000"><ele>10.0</ele></trkpt>
      <trkpt lat="0.0000000" lon="0.0100000"><ele>20.0</ele></trkpt>
    </trkseg>
  </trk>
</gpx>
```

`tests/Fixtures/gpx/crossing-b.gpx`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="track-forge-tests" xmlns="http://www.topografix.com/GPX/1/1">
  <trk>
    <name>Crossing B</name>
    <trkseg>
      <trkpt lat="-0.0030000" lon="0.0040000"><ele>5.0</ele></trkpt>
      <trkpt lat="0.0000000" lon="0.0040000"><ele>15.0</ele></trkpt>
      <trkpt lat="0.0050000" lon="0.0040000"><ele>5.0</ele></trkpt>
    </trkseg>
  </trk>
</gpx>
```

`tests/Fixtures/gpx/no-crossing.gpx` (far from both, ~1.1 km north):
```xml
<?xml version="1.0" encoding="UTF-8"?>
<gpx version="1.1" creator="track-forge-tests" xmlns="http://www.topografix.com/GPX/1/1">
  <trk>
    <name>No Crossing</name>
    <trkseg>
      <trkpt lat="0.0200000" lon="0.0000000"><ele>0.0</ele></trkpt>
      <trkpt lat="0.0200000" lon="0.0050000"><ele>0.0</ele></trkpt>
      <trkpt lat="0.0200000" lon="0.0100000"><ele>0.0</ele></trkpt>
    </trkseg>
  </trk>
</gpx>
```

- [ ] **Step 2: Write the failing tests**

`tests/Unit/Service/FileUploaderServiceTest.php`:
```php
<?php

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Service\FileUploaderService;
use PHPUnit\Framework\TestCase;

class FileUploaderServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tf-uploader-' . uniqid();
    }

    protected function tearDown(): void
    {
        (new FileUploaderService($this->dir))->deleteDirectory($this->dir);
    }

    public function testStoreContentsWritesTheFileIntoTheUsersFolder(): void
    {
        $service = new FileUploaderService($this->dir);
        $user = new User('a@example.com');

        self::assertTrue($service->storeContents($user, '<gpx/>', 'x.gpx'));

        self::assertSame('<gpx/>', file_get_contents($service->getUserDataPath($user) . '/x.gpx'));
    }
}
```

`tests/Unit/Service/RouteSuggestionServiceTest.php`:
```php
<?php

namespace App\Tests\Unit\Service;

use App\Entity\Track;
use App\Entity\User;
use App\Exception\RouteSuggestionException;
use App\Gps\GpsTrack;
use App\Gps\Route\CrossingDetector;
use App\Gps\Route\RouteFinder;
use App\Gps\Route\TrackGraphBuilder;
use App\Service\FileUploaderService;
use App\Service\RouteSuggestionService;
use PHPUnit\Framework\TestCase;

class RouteSuggestionServiceTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/gpx/';

    private string $dir;
    private FileUploaderService $uploader;
    private User $user;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tf-suggest-' . uniqid();
        $this->uploader = new FileUploaderService($this->dir);
        $this->user = new User('a@example.com');
    }

    protected function tearDown(): void
    {
        $this->uploader->deleteDirectory($this->dir);
    }

    private function service(int $maxTracks = 6): RouteSuggestionService
    {
        return new RouteSuggestionService(
            new GpsTrack(),
            $this->uploader,
            new CrossingDetector(25.0),
            new TrackGraphBuilder(),
            new RouteFinder(5, 100000),
            $maxTracks,
        );
    }

    private function track(string $name, string $contents): Track
    {
        $filename = uniqid() . '.gpx';
        $this->uploader->storeContents($this->user, $contents, $filename);

        return new Track($this->user, $name, $filename);
    }

    private function fixtureTrack(string $name, string $fixture): Track
    {
        return $this->track($name, (string) file_get_contents(self::FIXTURES . $fixture));
    }

    public function testCrossingTracksProduceRankedSuggestions(): void
    {
        $routes = $this->service()->suggest([
            $this->fixtureTrack('A', 'crossing-a.gpx'),
            $this->fixtureTrack('B', 'crossing-b.gpx'),
        ]);

        self::assertCount(4, $routes);
        self::assertGreaterThan($routes[3]->distance, $routes[0]->distance);
        self::assertSame([0, 1], $routes[0]->trackIndexes);
    }

    public function testTracksThatNeverCrossProduceNoSuggestions(): void
    {
        $routes = $this->service()->suggest([
            $this->fixtureTrack('A', 'crossing-a.gpx'),
            $this->fixtureTrack('Far', 'no-crossing.gpx'),
        ]);

        self::assertSame([], $routes);
    }

    public function testFewerThanTwoTracksIsRejected(): void
    {
        $this->expectException(RouteSuggestionException::class);
        $this->expectExceptionMessage('route.error.too_few_tracks');

        $this->service()->suggest([$this->fixtureTrack('A', 'crossing-a.gpx')]);
    }

    public function testMoreThanTheMaximumNumberOfTracksIsRejected(): void
    {
        $this->expectException(RouteSuggestionException::class);
        $this->expectExceptionMessage('route.error.too_many_tracks');

        $this->service(2)->suggest([
            $this->fixtureTrack('A', 'crossing-a.gpx'),
            $this->fixtureTrack('B', 'crossing-b.gpx'),
            $this->fixtureTrack('C', 'no-crossing.gpx'),
        ]);
    }

    public function testAMissingFileIsReportedWithTheTrackName(): void
    {
        $missing = new Track($this->user, 'Ghost', 'nope.gpx');

        try {
            $this->service()->suggest([$this->fixtureTrack('A', 'crossing-a.gpx'), $missing]);
            self::fail('Expected an exception');
        } catch (RouteSuggestionException $e) {
            self::assertSame('route.error.track_file_missing', $e->getMessage());
            self::assertSame(['%name%' => 'Ghost'], $e->getParameters());
        }
    }

    public function testATrackOverThePointCapIsRejected(): void
    {
        $points = '';
        for ($i = 0; $i <= 20000; $i++) {
            $points .= sprintf('<trkpt lat="0" lon="%.7f"><ele>0</ele></trkpt>', $i * 0.000001);
        }
        $big = $this->track('Big', '<?xml version="1.0"?><gpx version="1.1" creator="t" xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg>' . $points . '</trkseg></trk></gpx>');

        $this->expectException(RouteSuggestionException::class);
        $this->expectExceptionMessage('route.error.track_too_large');

        $this->service()->suggest([$this->fixtureTrack('A', 'crossing-a.gpx'), $big]);
    }

    public function testATrackWithFewerThanTwoDistinctPointsIsRejected(): void
    {
        $dot = $this->track('Dot', '<?xml version="1.0"?><gpx version="1.1" creator="t" xmlns="http://www.topografix.com/GPX/1/1"><trk><trkseg><trkpt lat="0" lon="0"/><trkpt lat="0" lon="0"/></trkseg></trk></gpx>');

        $this->expectException(RouteSuggestionException::class);
        $this->expectExceptionMessage('route.error.track_too_short');

        $this->service()->suggest([$this->fixtureTrack('A', 'crossing-a.gpx'), $dot]);
    }
}
```

- [ ] **Step 3: Run to verify failure**

Run: `vendor/bin/simple-phpunit tests/Unit/Service`
Expected: FAIL (classes/methods not found).

- [ ] **Step 4: Implement**

`src/Exception/RouteSuggestionException.php`:
```php
<?php

namespace App\Exception;

class RouteSuggestionException extends \RuntimeException
{
    /** @param array<string, string|int> $parameters */
    public function __construct(string $translationKey, private readonly array $parameters = [])
    {
        parent::__construct($translationKey);
    }

    /** @return array<string, string|int> */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}
```

Add to `src/Service/FileUploaderService.php` after `uploadFile()`:
```php
    public function storeContents(User $user, string $contents, string $targetFileName): bool
    {
        $userFolder = $this->getUserDataPath($user);

        if (!is_dir($userFolder) && !mkdir($userFolder, 0775, true) && !is_dir($userFolder)) {
            return false;
        }

        return file_put_contents($userFolder . '/' . $targetFileName, $contents) !== false;
    }
```

`src/Service/RouteSuggestionService.php`:
```php
<?php

namespace App\Service;

use App\Entity\Track;
use App\Exception\RouteSuggestionException;
use App\Gps\GpsTrack;
use App\Gps\Route\CrossingDetector;
use App\Gps\Route\PlanarTrack;
use App\Gps\Route\RouteFinder;
use App\Gps\Route\RoutePoint;
use App\Gps\Route\SuggestedRoute;
use App\Gps\Route\TrackGraphBuilder;

class RouteSuggestionService
{
    private const MAX_POINTS_PER_TRACK = 20000;

    public function __construct(
        private readonly GpsTrack $gpsTrack,
        private readonly FileUploaderService $fileUploaderService,
        private readonly CrossingDetector $crossingDetector,
        private readonly TrackGraphBuilder $graphBuilder,
        private readonly RouteFinder $routeFinder,
        private readonly int $routeMaxTracks,
    ) {
    }

    /**
     * Ranked route suggestions (longest first) stitched from the given tracks; empty when no
     * route rides every track.
     *
     * @param list<Track> $tracks
     * @return list<SuggestedRoute>
     */
    public function suggest(array $tracks): array
    {
        $tracks = array_values($tracks);
        if (count($tracks) < 2) {
            throw new RouteSuggestionException('route.error.too_few_tracks');
        }
        if (count($tracks) > $this->routeMaxTracks) {
            throw new RouteSuggestionException('route.error.too_many_tracks', ['%max%' => $this->routeMaxTracks]);
        }

        $pointLists = array_map(fn (Track $track): array => $this->loadPoints($track), $tracks);

        $latitudes = [];
        foreach ($pointLists as $points) {
            foreach ($points as $point) {
                $latitudes[] = $point->lat;
            }
        }
        $originLat = $latitudes === [] ? 0.0 : array_sum($latitudes) / count($latitudes);

        $planar = [];
        foreach ($pointLists as $i => $points) {
            try {
                $planar[] = new PlanarTrack($points, $originLat);
            } catch (\InvalidArgumentException) {
                throw new RouteSuggestionException('route.error.track_too_short', ['%name%' => $tracks[$i]->getName()]);
            }
        }

        $crossings = $this->crossingDetector->detect($planar);

        return $this->routeFinder->find($this->graphBuilder->build($planar, $crossings));
    }

    /** @return list<RoutePoint> */
    private function loadPoints(Track $track): array
    {
        $path = $this->fileUploaderService->getUserDataPath($track->getUser()) . '/' . $track->getFilename();
        if (!is_file($path)) {
            throw new RouteSuggestionException('route.error.track_file_missing', ['%name%' => $track->getName()]);
        }

        $this->gpsTrack->process($path);
        $points = $this->gpsTrack->getRawPoints();
        if (count($points) > self::MAX_POINTS_PER_TRACK) {
            throw new RouteSuggestionException('route.error.track_too_large', [
                '%name%' => $track->getName(),
                '%max%' => self::MAX_POINTS_PER_TRACK,
            ]);
        }

        return $points;
    }
}
```

`config/services.yaml`: under `parameters:` add
```yaml
    # Defaults so an existing deployment's .env (which predates these) still boots; override via env.
    env(ROUTE_CROSSING_TOLERANCE_M): '25'
    env(ROUTE_MAX_SUGGESTIONS): '5'
    env(ROUTE_MAX_TRACKS): '6'
    env(ROUTE_SEARCH_STEP_CAP): '100000'
```
and under `_defaults.bind:` add
```yaml
            float $routeCrossingToleranceM: '%env(float:ROUTE_CROSSING_TOLERANCE_M)%'
            int $routeMaxSuggestions: '%env(int:ROUTE_MAX_SUGGESTIONS)%'
            int $routeMaxTracks: '%env(int:ROUTE_MAX_TRACKS)%'
            int $routeSearchStepCap: '%env(int:ROUTE_SEARCH_STEP_CAP)%'
```

Append to `.env.dist`:
```
# Route suggestion: tracks closer than this many metres count as crossing; at most this many
# ranked suggestions are shown; at most this many tracks can be combined at once; the route
# search gives up after this many steps (guards against tracks that cross very many times).
ROUTE_CROSSING_TOLERANCE_M=25
ROUTE_MAX_SUGGESTIONS=5
ROUTE_MAX_TRACKS=6
ROUTE_SEARCH_STEP_CAP=100000
```

- [ ] **Step 5: Run to verify pass and the container compiles**

Run: `vendor/bin/simple-phpunit tests/Unit && bin/console lint:container && vendor/bin/phpcs && vendor/bin/phpstan analyse`
Expected: PASS / clean. `lint:container` confirms the scalar binds resolve.

- [ ] **Step 6: Commit**

```bash
git add src config .env.dist tests
git commit -m "feat(route): add RouteSuggestionService, config parameters and storeContents"
```

---

### Task 7: Suggest endpoint, results page, selection UI, translations

**Files:**
- Create: `src/Controller/RouteSuggestionController.php` (suggest action only in this task), `templates/Default/route-suggestions.html.twig`
- Create: `tests/Functional/RouteSuggestionControllerTest.php`
- Modify: `templates/Default/home.html.twig`, `translations/messages.en.yaml`, `translations/messages.pt.yaml`, `tests/Functional/WebTestCase.php`, `tests/Functional/HomeControllerTest.php`

**Interfaces:**
- Consumes: `RouteSuggestionService::suggest`, `RouteSuggestionException`, `TrackRepository::findOneByKey`, `FileUploaderService`.
- Produces:
  - Route `app_route_suggest` (POST; `/en/routes/suggest`, `/pt/rotas/sugerir`); form fields `_token` (id `route-suggest`) and `trackKeys[]`.
  - Controller private `resolveTracks(Request $request): list<Track>` (reused by Task 8): reads `trackKeys[]`, de-duplicates, throws `NotFoundHttpException` for unknown keys or tracks not owned by the user.
  - `WebTestCase::persistTrackFromFixture(User $user, string $name, string $fixture): Track`.
  - Template variables for `route-suggestions.html.twig`: `routes` (list of `array{index: int, distanceKm: float, elevation: float, trackNames: list<string>, latLngs: list<array{0: float, 1: float}>}`), `trackKeys` (list<string>).

- [ ] **Step 1: Test helper and failing tests**

Add to `tests/Functional/WebTestCase.php` (imports `App\Entity\Track`):
```php
    protected function persistTrackFromFixture(User $user, string $name, string $fixture): Track
    {
        $track = new Track($user, $name, uniqid('track-', true) . '.gpx');
        $this->entityManager->persist($track);
        $this->entityManager->flush();

        $dataPath = self::getContainer()->getParameter('app.data_path');
        \assert(is_string($dataPath));
        $userFolder = sprintf('%s/profile-%03d', $dataPath, (int) $user->getId());
        if (!is_dir($userFolder)) {
            mkdir($userFolder, 0775, true);
        }
        copy(__DIR__ . '/../Fixtures/gpx/' . $fixture, $userFolder . '/' . $track->getFilename());

        return $track;
    }
```

`tests/Functional/RouteSuggestionControllerTest.php`:
```php
<?php

namespace App\Tests\Functional;

class RouteSuggestionControllerTest extends WebTestCase
{
    public function testSuggestingFromTwoCrossingTracksListsRankedRoutes(): void
    {
        $user = $this->persistUser();
        $a = $this->persistTrackFromFixture($user, 'Track A', 'crossing-a.gpx');
        $b = $this->persistTrackFromFixture($user, 'Track B', 'crossing-b.gpx');
        $this->client->loginUser($user);

        $this->client->request('POST', '/en/routes/suggest', [
            '_token' => $this->suggestToken(),
            'trackKeys' => [$a->getKey(), $b->getKey()],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(4, '[data-route-card]');
        self::assertSelectorTextContains('[data-route-card="1"]', 'Track A');
        self::assertSelectorTextContains('[data-route-card="1"]', 'Track B');
        self::assertSelectorExists('[data-route-card="1"] form[action="/routes/save"]');
    }

    public function testPortugueseRouteIsAvailable(): void
    {
        $user = $this->persistUser();
        $a = $this->persistTrackFromFixture($user, 'Track A', 'crossing-a.gpx');
        $b = $this->persistTrackFromFixture($user, 'Track B', 'crossing-b.gpx');
        $this->client->loginUser($user);

        $this->client->request('POST', '/pt/rotas/sugerir', [
            '_token' => $this->suggestToken(),
            'trackKeys' => [$a->getKey(), $b->getKey()],
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testTracksThatNeverCrossShowTheNoRouteMessage(): void
    {
        $user = $this->persistUser();
        $a = $this->persistTrackFromFixture($user, 'Track A', 'crossing-a.gpx');
        $far = $this->persistTrackFromFixture($user, 'Far', 'no-crossing.gpx');
        $this->client->loginUser($user);

        $this->client->request('POST', '/en/routes/suggest', [
            '_token' => $this->suggestToken(),
            'trackKeys' => [$a->getKey(), $far->getKey()],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-route-card]');
        self::assertSelectorTextContains('.alert-info', 'No route');
    }

    public function testAnotherUsersTrackIsNotFound(): void
    {
        $owner = $this->persistUser('owner@example.com');
        $theirs = $this->persistTrackFromFixture($owner, 'Theirs', 'crossing-b.gpx');
        $user = $this->persistUser('me@example.com');
        $mine = $this->persistTrackFromFixture($user, 'Mine', 'crossing-a.gpx');
        $this->client->loginUser($user);

        $this->client->request('POST', '/en/routes/suggest', [
            '_token' => $this->suggestToken(),
            'trackKeys' => [$mine->getKey(), $theirs->getKey()],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testMissingCsrfTokenIsForbidden(): void
    {
        $user = $this->persistUser();
        $a = $this->persistTrackFromFixture($user, 'A', 'crossing-a.gpx');
        $b = $this->persistTrackFromFixture($user, 'B', 'crossing-b.gpx');
        $this->client->loginUser($user);

        $this->client->request('POST', '/en/routes/suggest', ['trackKeys' => [$a->getKey(), $b->getKey()]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testFewerThanTwoTracksRedirectsBackWithAnError(): void
    {
        $user = $this->persistUser();
        $a = $this->persistTrackFromFixture($user, 'A', 'crossing-a.gpx');
        $this->client->loginUser($user);

        $this->client->request('POST', '/en/routes/suggest', [
            '_token' => $this->suggestToken(),
            'trackKeys' => [$a->getKey()],
        ]);

        self::assertResponseRedirects('/en/tracks/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'at least two');
    }

    public function testTheSameTrackKeyTwiceCountsAsOneTrack(): void
    {
        $user = $this->persistUser();
        $a = $this->persistTrackFromFixture($user, 'A', 'crossing-a.gpx');
        $this->client->loginUser($user);

        $this->client->request('POST', '/en/routes/suggest', [
            '_token' => $this->suggestToken(),
            'trackKeys' => [$a->getKey(), $a->getKey()],
        ]);

        self::assertResponseRedirects('/en/tracks/');
    }

    public function testUnauthenticatedVisitorsAreSentToLogin(): void
    {
        $this->client->request('POST', '/en/routes/suggest', ['trackKeys' => ['x', 'y']]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    private function suggestToken(): string
    {
        $crawler = $this->client->request('GET', '/en/tracks/');

        return (string) $crawler->filter('form[action="/en/routes/suggest"] input[name="_token"]')->attr('value');
    }
}
```
Note: `suggestToken()` reads the token from the home page form, so the home page must render that form even with no tracks selected (Step 3 below renders the form whenever the user has tracks; the tests above persist tracks first). The unauthenticated and missing-CSRF tests do not call it.

Append to `tests/Functional/HomeControllerTest.php` (adapt to the file's existing helpers; it needs a user with a persisted track via `persistTrackFromFixture`):
```php
    public function testTrackListOffersSelectionCheckboxesAndASuggestButton(): void
    {
        $user = $this->persistUser();
        $track = $this->persistTrackFromFixture($user, 'Track A', 'crossing-a.gpx');
        $this->client->loginUser($user);

        $this->client->request('GET', '/en/tracks/');

        self::assertSelectorExists('form[action="/en/routes/suggest"] input[type="checkbox"][name="trackKeys[]"][value="' . $track->getKey() . '"]');
        self::assertSelectorExists('form[action="/en/routes/suggest"] button[type="submit"][disabled]');
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/simple-phpunit tests/Functional/RouteSuggestionControllerTest.php tests/Functional/HomeControllerTest.php`
Expected: FAIL (404 for the route / selectors missing).

- [ ] **Step 3: Implement**

`src/Controller/RouteSuggestionController.php`:
```php
<?php

namespace App\Controller;

use App\Entity\Track;
use App\Entity\User;
use App\Exception\RouteSuggestionException;
use App\Gps\Route\SuggestedRoute;
use App\Repository\TrackRepository;
use App\Service\RouteSuggestionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class RouteSuggestionController extends AbstractController
{
    public function __construct(
        private readonly TrackRepository $trackRepository,
        private readonly RouteSuggestionService $routeSuggestionService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: ['en' => '/en/routes/suggest', 'pt' => '/pt/rotas/sugerir'], name: 'app_route_suggest', methods: ['POST'])]
    public function suggest(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('route-suggest', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        $tracks = $this->resolveTracks($request);

        try {
            $routes = $this->routeSuggestionService->suggest($tracks);
        } catch (RouteSuggestionException $e) {
            $this->addFlash('danger', $this->translator->trans($e->getMessage(), $e->getParameters()));

            return $this->redirectToRoute('app_tracks');
        }

        $trackNames = array_map(static fn (Track $track): string => $track->getName(), $tracks);

        return $this->render('Default/route-suggestions.html.twig', [
            'routes' => array_map(
                static fn (SuggestedRoute $route, int $index): array => [
                    'index' => $index,
                    'distanceKm' => $route->distance / 1000,
                    'elevation' => $route->elevationGain(),
                    'trackNames' => array_map(static fn (int $i): string => $trackNames[$i], $route->trackIndexes),
                    'latLngs' => array_map(static fn ($p): array => [$p->lat, $p->lon], $route->points),
                ],
                $routes,
                array_keys($routes)
            ),
            'trackKeys' => array_map(static fn (Track $track): string => $track->getKey(), $tracks),
        ]);
    }

    /**
     * The distinct tracks named by `trackKeys[]`, all of which must belong to the current user.
     *
     * @return list<Track>
     */
    private function resolveTracks(Request $request): array
    {
        /** @var User $user */
        $user = $this->getUser();

        $keys = array_values(array_unique(array_filter(
            $request->request->all('trackKeys'),
            'is_string'
        )));

        $tracks = [];
        foreach ($keys as $key) {
            $track = $this->trackRepository->findOneByKey($key);
            if ($track === null || $track->getUser() !== $user) {
                throw new NotFoundHttpException('Track not found');
            }
            $tracks[] = $track;
        }

        return $tracks;
    }
}
```

`templates/Default/route-suggestions.html.twig`:
```twig
{% extends "Default/base.html.twig" %}

{% block title %}{{ 'route.title'|trans }}{% endblock %}

{% block stylesheets %}
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css"
          integrity="sha512-xodZBNTC5n17Xt2atTPuE1HxjVMSvLVW9ocqUKLsCC5CXdbqCmblAshOMAS6/keqq/sMZMZ19scR4PsZChSR7A=="
          crossorigin=""/>
{% endblock %}

{% block content %}
    <div class="row mb-4">
        <div class="col-12">
            <h1 class="h3">{{ 'route.title'|trans }}</h1>
            <p class="text-muted">{{ 'route.intro'|trans }}</p>
        </div>
    </div>

    {% if routes is empty %}
        <div class="alert alert-info">{{ 'route.none_found'|trans }}</div>
    {% endif %}

    {% for route in routes %}
        <div class="row mb-4" data-route-card="{{ route.index + 1 }}">
            <div class="col-12">
                <div class="card border shadow-sm">
                    <div class="card-header">
                        <h2 class="card-title h4">{{ 'route.option'|trans({'%n%': route.index + 1}) }}</h2>
                    </div>
                    <div class="card-body">
                        <div id="route-map-{{ route.index }}" class="rounded mb-3" style="height: 280px;"></div>
                        <span class="stat-pill">{{ route.distanceKm|number_format(2, '.', ',') }} Km</span>
                        <span class="stat-pill stat-pill-elevation">{{ route.elevation|number_format(0, '.', ',') }} m</span>
                        <p class="mt-3 mb-0">{{ 'route.uses'|trans }} {{ route.trackNames|join(', ') }}</p>
                    </div>
                    <div class="card-footer">
                        <form method="post" action="{{ path('app_route_save') }}">
                            <input type="hidden" name="_token" value="{{ csrf_token('route-save') }}"/>
                            <input type="hidden" name="routeIndex" value="{{ route.index }}"/>
                            {% for key in trackKeys %}
                                <input type="hidden" name="trackKeys[]" value="{{ key }}"/>
                            {% endfor %}
                            <button type="submit" class="btn btn-primary">{{ 'route.save'|trans }}</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    {% endfor %}

    <a href="{{ path('app_tracks') }}" class="btn btn-secondary">
        <i class="bi bi-list"></i> {{ 'track.back_to_list'|trans }}
    </a>
{% endblock %}

{% block javascripts %}
    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"
            integrity="sha512-XQoYMqMTK8LvdxXYG3nZ448hOEQiglfqkJs1NOQV44cWnUrBc8PkAOcXy20w0vlaXaVUearIOBhiXZ5V3ynxwA=="
            crossorigin=""></script>
    <script>
        const routes = {{ routes|map(r => {index: r.index, latLngs: r.latLngs})|json_encode|raw }};
        routes.forEach(function (route) {
            const map = L.map('route-map-' + route.index);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 18,
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            }).addTo(map);
            const line = L.polyline(route.latLngs, {color: '#ff7a00'}).addTo(map);
            map.fitBounds(line.getBounds());
        });
    </script>
{% endblock %}
```
(`route.latLngs` contains only floats, so `json_encode|raw` is safe here.)

In `templates/Default/home.html.twig`, wrap the track table: replace `{% if tracks %}` … the `<div class="table-responsive">` block so that it sits inside
```twig
<form method="post" action="{{ path('app_route_suggest') }}" id="route-form">
    <input type="hidden" name="_token" value="{{ csrf_token('route-suggest') }}"/>
    ...table...
    <div class="mt-3">
        <button type="submit" class="btn btn-secondary" id="route-submit" disabled>
            <i class="bi bi-signpost-split"></i> {{ 'route.suggest_button'|trans }}
        </button>
        <small class="text-muted ms-2">{{ 'route.select_hint'|trans }}</small>
    </div>
</form>
```
add a first header cell `<th></th>` and a first body cell
```twig
<td><input type="checkbox" class="form-check-input" name="trackKeys[]" value="{{ track.key }}" aria-label="{{ 'route.select_track'|trans({'%name%': track.name}) }}"/></td>
```
and add at the end of the template:
```twig
{% block javascripts %}
    <script>
        (function () {
            const form = document.getElementById('route-form');
            if (!form) { return; }
            const submit = document.getElementById('route-submit');
            const boxes = form.querySelectorAll('input[type="checkbox"][name="trackKeys[]"]');
            const update = function () {
                submit.disabled = form.querySelectorAll('input[type="checkbox"][name="trackKeys[]"]:checked').length < 2;
            };
            boxes.forEach(function (box) { box.addEventListener('change', update); });
            update();
        })();
    </script>
{% endblock %}
```
(The form only renders inside `{% if tracks %}`, so the empty state is unchanged. `base.html.twig` already defines `{% block javascripts %}` after its own scripts, so this runs after Bootstrap.)

Because `route-suggestions.html.twig` links to `app_route_save` (added in Task 8), create that route first as a stub only if the suggest tests render before Task 8 — to keep this task self-contained, add the save action in this task as the minimal `POST /routes/save` returning `$this->redirectToRoute('app_tracks')`, and replace it in Task 8:
```php
    #[Route('/routes/save', name: 'app_route_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        return $this->redirectToRoute('app_tracks');
    }
```

Translations — add to `translations/messages.en.yaml`:
```yaml
route:
    title: Suggested routes
    intro: Routes built by following your selected tracks and switching between them where they cross. Longest first.
    suggest_button: Suggest route
    select_hint: Select 2 or more tracks
    select_track: 'Select %name%'
    option: 'Suggestion %n%'
    uses: 'Uses:'
    save: Save as track
    none_found: No route found. The selected tracks do not cross each other in a way that lets one route ride all of them.
    default_name: 'Suggested route - %date%'
    error:
        too_few_tracks: Select at least two tracks.
        too_many_tracks: 'You can combine at most %max% tracks at once.'
        track_file_missing: 'The file for track "%name%" is missing.'
        track_too_large: 'Track "%name%" has too many points (the limit is %max%).'
        track_too_short: 'Track "%name%" does not have enough distinct points.'
        not_found: That suggestion is no longer available. Please try again.
```
and under the existing `flash:` map add `route_saved: Route saved as a new track` and `route_save_failed: Unable to save the route`.

`translations/messages.pt.yaml` (add the same structure):
```yaml
route:
    title: Percursos sugeridos
    intro: Percursos construídos a seguir os percursos selecionados e a trocar entre eles nos pontos onde se cruzam. Os mais longos primeiro.
    suggest_button: Sugerir percurso
    select_hint: Selecione 2 ou mais percursos
    select_track: 'Selecionar %name%'
    option: 'Sugestão %n%'
    uses: 'Usa:'
    save: Guardar como percurso
    none_found: Nenhum percurso encontrado. Os percursos selecionados não se cruzam de forma a que um único percurso passe por todos.
    default_name: 'Percurso sugerido - %date%'
    error:
        too_few_tracks: Selecione pelo menos dois percursos.
        too_many_tracks: 'Só pode combinar no máximo %max% percursos de cada vez.'
        track_file_missing: 'O ficheiro do percurso "%name%" não existe.'
        track_too_large: 'O percurso "%name%" tem demasiados pontos (o limite é %max%).'
        track_too_short: 'O percurso "%name%" não tem pontos distintos suficientes.'
        not_found: Essa sugestão já não está disponível. Tente novamente.
```
and under `flash:` in the pt file: `route_saved: Percurso guardado como novo percurso` and `route_save_failed: Não foi possível guardar o percurso`.

Make sure the test assertions match the English strings exactly: the fewer-than-two message must contain "at least two", the no-route alert must contain "No route".

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/simple-phpunit tests/Functional && vendor/bin/phpcs && vendor/bin/phpstan analyse`
Expected: PASS / clean. Also run `bin/console debug:translation en --only-missing` and `bin/console debug:translation pt --only-missing` and fix any missing `route.*`/`flash.route_*` keys.

- [ ] **Step 5: Commit**

```bash
git add src templates translations tests
git commit -m "feat(route): suggest routes from selected tracks"
```

---

### Task 8: Save endpoint

**Files:**
- Modify: `src/Controller/RouteSuggestionController.php`
- Modify: `tests/Functional/RouteSuggestionControllerTest.php`

**Interfaces:**
- Consumes: `resolveTracks()` (Task 7), `RouteSuggestionService::suggest`, `GpxExporter::export`, `FileUploaderService::storeContents/getUserDataPath`, `GpsTrack::process/getInfo/getName/getRecordedAt`, `Track` setters, `EntityManagerInterface`, `User::getLocale()`.
- Produces: route `app_route_save` (POST `/routes/save`; fields `_token` id `route-save`, `routeIndex`, `trackKeys[]`). On success it creates a `Track` and redirects to `app_track_info` (`_locale` = user locale, `trackKey`).

- [ ] **Step 1: Write the failing tests**

Add to `RouteSuggestionControllerTest`:
```php
    public function testSavingASuggestionCreatesATrackWithAFileAndStats(): void
    {
        $user = $this->persistUser();
        $a = $this->persistTrackFromFixture($user, 'Track A', 'crossing-a.gpx');
        $b = $this->persistTrackFromFixture($user, 'Track B', 'crossing-b.gpx');
        $this->client->loginUser($user);
        $token = $this->suggestToken();

        $crawler = $this->client->request('POST', '/en/routes/suggest', [
            '_token' => $token,
            'trackKeys' => [$a->getKey(), $b->getKey()],
        ]);
        $saveToken = (string) $crawler->filter('[data-route-card="1"] form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/routes/save', [
            '_token' => $saveToken,
            'routeIndex' => 0,
            'trackKeys' => [$a->getKey(), $b->getKey()],
        ]);

        $tracks = $this->entityManager->getRepository(\App\Entity\Track::class)->findBy(['user' => $user], ['id' => 'DESC']);
        self::assertCount(3, $tracks);
        $saved = $tracks[0];
        self::assertStringStartsWith('Suggested route - ', $saved->getName());
        self::assertNotNull($saved->getDistance());
        // The longest suggestion rides A's longer piece (~667 m) and B's longer piece (~556 m).
        self::assertEqualsWithDelta(1.22, $saved->getDistance(), 0.05);
        self::assertGreaterThan(3, $saved->getTotalPoints());
        self::assertNull($saved->getRecordedAt());
        $path = self::getContainer()->getParameter('app.data_path')
            . sprintf('/profile-%03d/%s', $user->getId(), $saved->getFilename());
        self::assertFileExists($path);
        self::assertResponseRedirects('/en/track/info/' . $saved->getKey());
    }

    public function testSavingAnOutOfRangeRouteIndexRedirectsWithAnError(): void
    {
        $user = $this->persistUser();
        $a = $this->persistTrackFromFixture($user, 'Track A', 'crossing-a.gpx');
        $b = $this->persistTrackFromFixture($user, 'Track B', 'crossing-b.gpx');
        $this->client->loginUser($user);
        $crawler = $this->client->request('POST', '/en/routes/suggest', [
            '_token' => $this->suggestToken(),
            'trackKeys' => [$a->getKey(), $b->getKey()],
        ]);
        $saveToken = (string) $crawler->filter('[data-route-card="1"] form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/routes/save', [
            '_token' => $saveToken,
            'routeIndex' => 99,
            'trackKeys' => [$a->getKey(), $b->getKey()],
        ]);

        self::assertResponseRedirects('/en/tracks/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'no longer available');
        self::assertSame(2, $this->entityManager->getRepository(\App\Entity\Track::class)->count(['user' => $user]));
    }

    public function testSavingWithoutACsrfTokenIsForbidden(): void
    {
        $user = $this->persistUser();
        $a = $this->persistTrackFromFixture($user, 'A', 'crossing-a.gpx');
        $b = $this->persistTrackFromFixture($user, 'B', 'crossing-b.gpx');
        $this->client->loginUser($user);

        $this->client->request('POST', '/routes/save', ['routeIndex' => 0, 'trackKeys' => [$a->getKey(), $b->getKey()]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSavingWithAnotherUsersTrackIsNotFound(): void
    {
        $owner = $this->persistUser('owner@example.com');
        $theirs = $this->persistTrackFromFixture($owner, 'Theirs', 'crossing-b.gpx');
        $user = $this->persistUser('me@example.com');
        $mine = $this->persistTrackFromFixture($user, 'Mine', 'crossing-a.gpx');
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/en/tracks/');
        // Any valid token for the save action: reuse one rendered by a legitimate suggestion page.
        $own = $this->persistTrackFromFixture($user, 'Own B', 'crossing-b.gpx');
        $page = $this->client->request('POST', '/en/routes/suggest', [
            '_token' => (string) $crawler->filter('form[action="/en/routes/suggest"] input[name="_token"]')->attr('value'),
            'trackKeys' => [$mine->getKey(), $own->getKey()],
        ]);
        $saveToken = (string) $page->filter('[data-route-card="1"] form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/routes/save', [
            '_token' => $saveToken,
            'routeIndex' => 0,
            'trackKeys' => [$mine->getKey(), $theirs->getKey()],
        ]);

        self::assertResponseStatusCodeSame(404);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/simple-phpunit tests/Functional/RouteSuggestionControllerTest.php`
Expected: FAIL (the stub redirects without saving).

- [ ] **Step 3: Implement**

Add constructor dependencies to `RouteSuggestionController` (imports: `App\Gps\GpsTrack`, `App\Gps\Route\GpxExporter`, `App\Service\FileUploaderService`, `Doctrine\ORM\EntityManagerInterface`):
```php
        private readonly GpxExporter $gpxExporter,
        private readonly FileUploaderService $fileUploaderService,
        private readonly GpsTrack $gpsTrack,
        private readonly EntityManagerInterface $entityManager,
```
Replace the stub `save()` with:
```php
    #[Route('/routes/save', name: 'app_route_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('route-save', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        /** @var User $user */
        $user = $this->getUser();
        $locale = $user->getLocale();

        $tracks = $this->resolveTracks($request);

        try {
            // Recompute rather than trust the client: no coordinates ever come from the request.
            $routes = $this->routeSuggestionService->suggest($tracks);
        } catch (RouteSuggestionException $e) {
            $this->addFlash('danger', $this->translator->trans($e->getMessage(), $e->getParameters(), null, $locale));

            return $this->redirectToRoute('app_tracks', ['_locale' => $locale]);
        }

        $index = (int) $request->request->get('routeIndex', -1);
        if (!isset($routes[$index])) {
            $this->addFlash('danger', $this->translator->trans('route.error.not_found', [], null, $locale));

            return $this->redirectToRoute('app_tracks', ['_locale' => $locale]);
        }

        $name = $this->translator->trans(
            'route.default_name',
            ['%date%' => (new \DateTimeImmutable())->format('Y-m-d H:i')],
            null,
            $locale
        );
        $targetFileName = uniqid() . '.gpx';
        $xml = $this->gpxExporter->export($routes[$index], $name);

        if (!$this->fileUploaderService->storeContents($user, $xml, $targetFileName)) {
            $this->addFlash('danger', $this->translator->trans('flash.route_save_failed', [], null, $locale));

            return $this->redirectToRoute('app_tracks', ['_locale' => $locale]);
        }

        $this->gpsTrack->process($this->fileUploaderService->getUserDataPath($user) . '/' . $targetFileName);
        $info = $this->gpsTrack->getInfo();

        $track = new Track($user, $name, $targetFileName);
        $track->setTotalPoints($info['points']);
        $track->setElevation((float) $info['totalHeight']);
        $track->setDistance((float) $info['totalDistance']);
        $track->setRecordedAt($this->gpsTrack->getRecordedAt());
        $track->setGpxName($this->gpsTrack->getName());

        $this->entityManager->persist($track);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('flash.route_saved', [], null, $locale));

        return $this->redirectToRoute('app_track_info', ['_locale' => $locale, 'trackKey' => $track->getKey()]);
    }
```
In `testSavingASuggestion...` the `Suggested route - ` prefix and ~1.22 km distance match the fixtures (A: 0.006° ≈ 667 m longer piece; B: 0.005° ≈ 556 m longer piece; total ≈ 1.22 km). The stored GPX distance is computed by `GpsTrack` (haversine), not by the suggestion.

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/simple-phpunit tests/Functional/RouteSuggestionControllerTest.php && vendor/bin/phpcs && vendor/bin/phpstan analyse`
Expected: PASS / clean. If the redirect assertion fails because `app_track_info` generates a different path, assert against `/en/track/info/<key>` as written.

- [ ] **Step 5: Commit**

```bash
git add src tests
git commit -m "feat(route): save a suggested route as a new track"
```

---

### Task 9: Documentation and full verification

**Files:**
- Modify: `CLAUDE.md`

**Interfaces:** none.

- [ ] **Step 1: Update CLAUDE.md**

In the Architecture section: add `RouteSuggestionController` to the controller list (`/en/routes/suggest` / `/pt/rotas/sugerir` POST, localized; `/routes/save` POST, unprefixed). Add a **Route suggestion** paragraph after **GPX handling**:
> **Route suggestion**: selecting 2+ tracks on the track list posts to `RouteSuggestionController`, which calls `App\Service\RouteSuggestionService` — it loads each stored GPX via `GpsTrack::getRawPoints()`, then runs the pure-PHP pipeline in `src/Gps/Route/`: `PlanarTrack` (polyline + distance-along-track positions) → `CrossingDetector` (intersections and proximity within `ROUTE_CROSSING_TOLERANCE_M`; a shared stretch collapses to an entry and an exit crossing) → `TrackGraphBuilder` (nodes = track endpoints + crossings, edges = track pieces + straight connectors) → `RouteFinder` (DFS from endpoints, no edge reused, must ride every selected track, ranked longest first, capped by `ROUTE_MAX_SUGGESTIONS` and `ROUTE_SEARCH_STEP_CAP`). Suggestions are never stored: `/routes/save` recomputes them from `trackKeys[]` + `routeIndex`, writes the chosen route with `GpxExporter` + `FileUploaderService::storeContents()`, and creates a normal `Track`. The four `ROUTE_*` env vars have defaults in `config/services.yaml` (`env(...)` parameters) so older `.env` files still boot.

- [ ] **Step 2: Run the full verification**

Run each and confirm clean/green:
```
vendor/bin/phpcs
vendor/bin/phpstan analyse
vendor/bin/simple-phpunit
bin/console lint:container
bin/console lint:twig templates
bin/console lint:yaml config translations
```
Expected: no errors; the whole suite passes (the existing tests plus the new ones).

- [ ] **Step 3: Manual check in the running app**

Start the app (`make start`), upload `tests/Fixtures/gpx/crossing-a.gpx` and `crossing-b.gpx` (name them A and B) on `/en/tracks/`, tick both, click "Suggest route": expect 4 cards with maps, longest first (~1.22 km); save the first and confirm it opens as a normal track with a map, stats and share behavior like an uploaded one. Check the Portuguese UI (`/pt/percursos/`) for untranslated labels.

- [ ] **Step 4: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: document route suggestion in CLAUDE.md"
```

---

## Self-Review

**Spec coverage:** 2–N own tracks and the `ROUTE_MAX_TRACKS` limit → Tasks 6, 7. Geometric and proximity crossings, tolerance 25 m, connector → Tasks 2, 3. Start and end only at endpoints, no edge reuse, every track ridden, longest first, top `ROUTE_MAX_SUGGESTIONS` → Task 4. Step cap → Task 4. GPX export that passes validation → Task 5. Suggest page with distance, elevation, tracks used and a map preview → Task 7. Save by recomputation, no client coordinates → Task 8. Error cases (no route, too many tracks, point cap, empty/short tracks, foreign track, CSRF, fewer than 2) → Tasks 6–8. i18n en/pt, flash messages in `User::locale` → Tasks 7–8. Config via env, bound in `services.yaml`, documented in `.env.dist` → Task 6. Docs → Task 9. Out-of-scope items (storing, mid-track start, loops, snapping, timestamps) are not built.

**Spec detail resolved here:** the spec says a route "never rides an edge against the direction it was entered". The plan reads this as "no edge is used twice, and no turning around mid-edge". Pieces can be ridden in either direction, once each, so a route may travel along a track backwards. If you meant "always in the track's original direction", `RouteEdge` and `RouteFinder::walk` need a direction rule.

**Placeholders:** none. Every code step shows the code.

**Type consistency:** `RoutePoint`, `PlanarTrack`, `Crossing`, `TrackGraph`, `RouteEdge::otherEnd/pointsFrom`, `SuggestedRoute`, `RouteFinder::find`, `RouteSuggestionService::suggest` and `GpxExporter::export` keep the same names and signatures across tasks. The container binds `routeCrossingToleranceM`, `routeMaxSuggestions`, `routeMaxTracks` and `routeSearchStepCap` match the constructor argument names.
