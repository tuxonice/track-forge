<?php

namespace App\Tests\Unit\Gps\Route;

use App\Gps\Route\CrossingDetector;
use App\Gps\Route\PlanarTrack;
use App\Gps\Route\RoutePoint;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return iterable<string, array{0: float, 1: float, 2: string}> */
    public static function glitchPoints(): iterable
    {
        yield 'stray point 2 degrees away in the second track' => [50.5, 11.42, 'b'];
        yield 'stray point 2 degrees away in the first track' => [50.5, 11.42, 'a'];
        yield 'null island in the second track' => [0.0, 0.0, 'b'];
        yield 'null island in the first track' => [0.0, 0.0, 'a'];
    }

    /**
     * A single GPS glitch point turns two segments into thousands of kilometres of line; the
     * detector must stay fast and small and still find the real crossing.
     */
    #[DataProvider('glitchPoints')]
    public function testAGpsGlitchPointStaysFastAndKeepsTheRealCrossing(float $lat, float $lon, string $which): void
    {
        // Berlin: A runs east along lat 52.5, B runs north along lon 13.42 and crosses it.
        $a = RouteTestHelper::dense([[52.5, 13.4], [52.5, 13.45]]);
        $b = RouteTestHelper::dense([[52.49, 13.42], [52.51, 13.42]]);
        $glitch = new RoutePoint($lat, $lon);
        if ($which === 'a') {
            array_splice($a, 100, 0, [$glitch]);
        } else {
            array_splice($b, 100, 0, [$glitch]);
        }

        memory_reset_peak_usage();
        $baseline = memory_get_usage();
        $started = microtime(true);
        $crossings = (new CrossingDetector(25.0))->detect([new PlanarTrack($a, 52.5), new PlanarTrack($b, 52.5)]);
        $elapsed = microtime(true) - $started;

        self::assertLessThan(2.0, $elapsed, 'detection took too long');
        self::assertLessThan(32 * 1024 * 1024, memory_get_peak_usage() - $baseline, 'detection used too much memory');

        // The real crossing at lon 13.42 on B: 0.01 deg north of B's start (when B has no glitch first).
        $real = array_filter($crossings, static fn ($c): bool => $c->gap < 0.001 && abs(
            ($which === 'a' ? $c->distanceB : $c->distanceA) - ($which === 'a' ? 0.01 * self::DEG : 0.02 * self::DEG * cos(deg2rad(52.5)))
        ) < 30.0);
        self::assertCount(1, $real);
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
