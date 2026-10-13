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

    public function testThePiecesOfBothTracksAlongASharedStretchFormOneCorridor(): void
    {
        $a = RouteTestHelper::track([[0.0, 0.0], [0.0, 0.002], [0.0, 0.004], [0.0, 0.006], [0.0, 0.008], [0.0, 0.01]]);
        // Runs ~11 m beside A from lon 0.002 to 0.008, away from it before and after.
        $b = RouteTestHelper::track([
            [0.003, 0.0], [0.0001, 0.002], [0.0001, 0.004], [0.0001, 0.006], [0.0001, 0.008], [0.003, 0.01],
        ]);
        $crossings = (new CrossingDetector(25.0))->detect([$a, $b]);
        self::assertNotNull($crossings[0]->stretchId);
        self::assertSame($crossings[0]->stretchId, $crossings[1]->stretchId);

        $graph = (new TrackGraphBuilder())->build([$a, $b], $crossings);

        $inCorridor = array_values(array_filter($graph->edges, static fn ($e): bool => $e->corridors !== []));
        self::assertCount(2, $inCorridor);
        self::assertSame([0, 1], array_map(static fn ($e): ?int => $e->trackIndex, $inCorridor));
        foreach ($inCorridor as $edge) {
            self::assertSame([$crossings[0]->stretchId], $edge->corridors);
            self::assertEqualsWithDelta(0.006 * 111194.93, $edge->length, 60.0);
        }
        // The pieces before and after the stretch, and the connectors, are in no corridor.
        self::assertCount(6, array_filter($graph->edges, static fn ($e): bool => $e->corridors === []));
    }

    public function testAnXCrossingMakesNoCorridor(): void
    {
        $a = RouteTestHelper::track([[0.0, 0.0], [0.0, 0.01]]);
        $b = RouteTestHelper::track([[-0.003, 0.004], [0.005, 0.004]]);
        $crossings = (new CrossingDetector(25.0))->detect([$a, $b]);

        $graph = (new TrackGraphBuilder())->build([$a, $b], $crossings);

        self::assertNull($crossings[0]->stretchId);
        foreach ($graph->edges as $edge) {
            self::assertSame([], $edge->corridors);
        }
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
