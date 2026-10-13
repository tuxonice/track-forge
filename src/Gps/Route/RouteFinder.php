<?php

namespace App\Gps\Route;

/**
 * Enumerates routes through a TrackGraph with a depth-first search: start at a track endpoint,
 * never use an edge twice, end at a track endpoint having ridden every track. Results are ranked
 * longest first.
 *
 * The same ground is never ridden twice: where two tracks share a stretch (a corridor, see
 * TrackGraphBuilder) a route may ride that stretch on one track's copy only, and riding a piece of
 * a corridor counts as riding every track in it. A route also never switches tracks through a
 * connector only to turn straight back the way it came. Routes that trace the same ground (the
 * same pieces, whichever track's copy of a corridor they use) are listed once, keeping the
 * shortest, i.e. the one with the least hopping between copies.
 *
 * The search backtracks over one shared path, records each route only from its lower-numbered end
 * and keeps just the best few, so memory stays small. It stops after a fixed number of steps so a
 * pathological graph cannot run away; endpoints are explored in node order, so when the cap is hit
 * the suggestions come from the routes starting at the first endpoints rather than the whole graph.
 */
final class RouteFinder
{
    /** A turn sharper than this many degrees after a connector counts as turning back. */
    private const U_TURN_DEGREES = 150.0;
    /** Metres along a piece used to measure the direction it leaves or enters a node. */
    private const HEADING_LOOK_M = 50.0;

    private int $steps = 0;
    private TrackGraph $graph;
    private int $startId = 0;
    /** @var list<int> edge ids ridden so far */
    private array $path = [];
    /** @var array<int, true> */
    private array $used = [];
    /** @var array<int, int> track index => pieces counting as riding it */
    private array $tracksUsed = [];
    /** @var array<int, array{0: int, 1: int}> corridor id => [track whose copy is in use, pieces of it ridden] */
    private array $corridorUse = [];
    /** @var list<array{0: float, 1: float}|null> per path step: direction of travel at its end (null for connectors) */
    private array $arrivals = [];
    /** @var array<int, list<int>> per edge: track indexes riding it counts for */
    private array $credits = [];
    /** @var array<int, int|string|null> per edge: what it is on the ground (piece id, corridor, null for connectors) */
    private array $ground = [];
    /** @var array<int, array{0: array{0: float, 1: float}|null, 1: array{0: float, 1: float}|null}> per piece: direction at its start and at its end */
    private array $headings = [];
    /** @var array<int, int> corridor id => another corridor on the same ground (union-find) */
    private array $corridorParent = [];
    /** @var array<string, float> ground signature => shortest distance seen */
    private array $shortestBySignature = [];
    /** @var list<array{distance: float, tie: string, signature: string, start: int, path: list<int>}> best routes, ranked */
    private array $kept = [];

    public function __construct(
        private readonly int $routeMaxSuggestions,
        private readonly int $routeSearchStepCap,
    ) {
    }

    /** @return list<SuggestedRoute> */
    public function find(TrackGraph $graph): array
    {
        $this->graph = $graph;
        $this->prepare($graph);
        $this->steps = 0;
        $this->kept = [];
        $this->shortestBySignature = [];

        if ($this->routeMaxSuggestions > 0) {
            foreach ($graph->nodes as $start) {
                if ($start->isEndpoint && $this->steps <= $this->routeSearchStepCap) {
                    $this->startId = $start->id;
                    $this->path = [];
                    $this->used = [];
                    $this->tracksUsed = [];
                    $this->corridorUse = [];
                    $this->arrivals = [];
                    $this->walk($start->id);
                }
            }
        }

        $routes = array_map(
            fn (array $route): SuggestedRoute => $this->build($graph, $route['start'], $route['path']),
            $this->kept
        );
        $this->kept = [];
        $this->shortestBySignature = [];

        return $routes;
    }

    private function walk(int $current): void
    {
        if (++$this->steps > $this->routeSearchStepCap) {
            return;
        }

        // Every route is found from both ends; record it from its lower-numbered end only.
        if (
            $current > $this->startId
            && $this->graph->nodes[$current]->isEndpoint
            && count($this->tracksUsed) === $this->graph->trackCount
        ) {
            $this->record();
        }

        foreach ($this->graph->adjacency[$current] as $edgeId) {
            if ($this->steps > $this->routeSearchStepCap) {
                return;
            }
            if (isset($this->used[$edgeId])) {
                continue;
            }
            $edge = $this->graph->edges[$edgeId];
            if (!$this->corridorsAllow($edge) || $this->turnsBack($edge, $current)) {
                continue;
            }

            $this->push($edge, $current);
            $this->walk($edge->otherEnd($current));
            $this->pop($edge);
        }
    }

    /** A corridor may be ridden on one track's copy only. */
    private function corridorsAllow(RouteEdge $edge): bool
    {
        foreach ($edge->corridors as $corridor) {
            if (isset($this->corridorUse[$corridor]) && $this->corridorUse[$corridor][0] !== $edge->trackIndex) {
                return false;
            }
        }

        return true;
    }

    /** Arriving on a piece, crossing a connector, then leaving on a piece back the way we came. */
    private function turnsBack(RouteEdge $edge, int $current): bool
    {
        $steps = count($this->path);
        if ($edge->trackIndex === null || $steps < 2 || $this->graph->edges[$this->path[$steps - 1]]->trackIndex !== null) {
            return false;
        }
        $arrival = $this->arrivals[$steps - 2];
        $departure = $this->departure($edge, $current);
        if ($arrival === null || $departure === null) {
            return false;
        }

        return $arrival[0] * $departure[0] + $arrival[1] * $departure[1] < cos(deg2rad(self::U_TURN_DEGREES));
    }

    private function push(RouteEdge $edge, int $from): void
    {
        $this->path[] = $edge->id;
        $this->used[$edge->id] = true;
        foreach ($this->credits[$edge->id] as $track) {
            $this->tracksUsed[$track] = ($this->tracksUsed[$track] ?? 0) + 1;
        }
        foreach ($edge->corridors as $corridor) {
            $this->corridorUse[$corridor] = [(int) $edge->trackIndex, ($this->corridorUse[$corridor][1] ?? 0) + 1];
        }
        $this->arrivals[] = $this->arrival($edge, $from);
    }

    private function pop(RouteEdge $edge): void
    {
        array_pop($this->path);
        array_pop($this->arrivals);
        unset($this->used[$edge->id]);
        foreach ($this->credits[$edge->id] as $track) {
            if (--$this->tracksUsed[$track] === 0) {
                unset($this->tracksUsed[$track]);
            }
        }
        foreach ($edge->corridors as $corridor) {
            if (--$this->corridorUse[$corridor][1] === 0) {
                unset($this->corridorUse[$corridor]);
            }
        }
    }

    /** Keep the current path if it is among the best few distinct routes. */
    private function record(): void
    {
        $distance = 0.0;
        $ground = [];
        foreach ($this->path as $edgeId) {
            $distance += $this->graph->edges[$edgeId]->length;
            $what = $this->ground[$edgeId];
            if ($what !== null && ($ground === [] || $ground[count($ground) - 1] !== $what)) {
                $ground[] = $what;
            }
        }
        $forward = implode(',', $ground);
        $backward = implode(',', array_reverse($ground));
        $signature = md5(strcmp($forward, $backward) <= 0 ? $forward : $backward, true);

        // The same ground ridden before (perhaps on another track's copy of a corridor): keep the shorter.
        if (isset($this->shortestBySignature[$signature]) && $this->shortestBySignature[$signature] <= $distance) {
            return;
        }
        $this->shortestBySignature[$signature] = $distance;

        $this->kept = array_values(array_filter(
            $this->kept,
            static fn (array $route): bool => $route['signature'] !== $signature
        ));
        $worst = $this->kept[count($this->kept) - 1] ?? null;
        $tie = md5(implode(',', $this->path), true);
        if (
            $worst !== null && count($this->kept) >= $this->routeMaxSuggestions
            && $this->ranksBefore($worst['distance'], $worst['tie'], $distance, $tie)
        ) {
            return;
        }

        $this->kept[] = [
            'distance' => $distance,
            'tie' => $tie,
            'signature' => $signature,
            'start' => $this->startId,
            'path' => $this->path,
        ];
        usort(
            $this->kept,
            fn (array $x, array $y): int => $this->ranksBefore($x['distance'], $x['tie'], $y['distance'], $y['tie']) ? -1 : 1
        );
        $this->kept = array_slice($this->kept, 0, $this->routeMaxSuggestions);
    }

    /** Longest first; equal lengths in a fixed but arbitrary order. */
    private function ranksBefore(float $distance, string $tie, float $otherDistance, string $otherTie): bool
    {
        return $distance > $otherDistance || ($distance === $otherDistance && strcmp($tie, $otherTie) < 0);
    }

    /** Per-edge lookups the search needs: tracks credited, ground identity, headings. */
    private function prepare(TrackGraph $graph): void
    {
        // Corridors that share a piece are the same ground (A-B, A-C and B-C on one stretch).
        $this->corridorParent = [];
        $tracksOf = [];
        foreach ($graph->edges as $edge) {
            foreach ($edge->corridors as $corridor) {
                $this->corridorParent[$corridor] ??= $corridor;
                $tracksOf[$corridor][(int) $edge->trackIndex] = (int) $edge->trackIndex;
            }
            foreach ($edge->corridors as $corridor) {
                $this->corridorParent[$this->corridorRoot($corridor)] = $this->corridorRoot($edge->corridors[0]);
            }
        }

        $this->credits = [];
        $this->ground = [];
        $this->headings = [];
        foreach ($graph->edges as $edge) {
            if ($edge->trackIndex === null) {
                $this->credits[$edge->id] = [];
                $this->ground[$edge->id] = null;
                continue;
            }
            $credits = [$edge->trackIndex => $edge->trackIndex];
            foreach ($edge->corridors as $corridor) {
                $credits += $tracksOf[$corridor];
            }
            $this->credits[$edge->id] = array_values($credits);
            $this->ground[$edge->id] = $edge->corridors === [] ? $this->footprint($edge) : 'c' . $this->corridorRoot($edge->corridors[0]);
            $this->headings[$edge->id] = [$this->heading($edge->points), $this->heading(array_reverse($edge->points))];
        }
    }

    /** The representative of the group of corridors that are all the same ground. */
    private function corridorRoot(int $corridor): int
    {
        while ($this->corridorParent[$corridor] !== $corridor) {
            $corridor = $this->corridorParent[$corridor];
        }

        return $corridor;
    }

    /**
     * A piece outside any corridor, identified by where it runs rather than which track it is on,
     * so that the same piece of ground recorded twice (a duplicate upload) is recognised as such.
     */
    private function footprint(RouteEdge $edge): string
    {
        $ends = [];
        foreach ([$edge->points[0], $edge->points[count($edge->points) - 1]] as $point) {
            $ends[] = sprintf('%.5F:%.5F', $point->lat, $point->lon);
        }
        sort($ends);

        return implode('~', $ends) . '~' . (int) round($edge->length);
    }

    /**
     * Unit direction (east, north) from the first point towards the point HEADING_LOOK_M along.
     *
     * @param list<RoutePoint> $points
     * @return array{0: float, 1: float}|null
     */
    private function heading(array $points): ?array
    {
        $first = $points[0];
        $along = 0.0;
        $target = $first;
        for ($i = 1; $i < count($points) && $along < self::HEADING_LOOK_M; $i++) {
            $along += Geo::distance($points[$i - 1], $points[$i]);
            $target = $points[$i];
        }
        $east = ($target->lon - $first->lon) * cos(deg2rad($first->lat));
        $north = $target->lat - $first->lat;
        $length = hypot($east, $north);

        return $length > 0.0 ? [$east / $length, $north / $length] : null;
    }

    /** @return array{0: float, 1: float}|null direction of travel leaving $nodeId along the piece */
    private function departure(RouteEdge $edge, int $nodeId): ?array
    {
        return $this->headings[$edge->id][$nodeId === $edge->from ? 0 : 1];
    }

    /** @return array{0: float, 1: float}|null direction of travel arriving at the far end, entering at $nodeId */
    private function arrival(RouteEdge $edge, int $nodeId): ?array
    {
        if ($edge->trackIndex === null) {
            return null;
        }
        // Arriving at one end is the reverse of departing from it.
        $leaving = $this->headings[$edge->id][$nodeId === $edge->from ? 1 : 0];

        return $leaving === null ? null : [-$leaving[0], -$leaving[1]];
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
            foreach ($this->credits[$edgeId] as $track) {
                $tracks[$track] = $track;
            }
            $current = $edge->otherEnd($current);
        }
        ksort($tracks);

        return new SuggestedRoute($points, $distance, array_values($tracks));
    }
}
