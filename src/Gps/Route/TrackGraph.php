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

    /**
     * @param list<RoutePoint> $points ordered from $from to $to
     * @param list<int> $corridors ids of the shared stretches this piece lies on
     */
    public function addEdge(int $from, int $to, ?int $trackIndex, float $length, array $points, array $corridors = []): void
    {
        $id = count($this->edges);
        $this->edges[] = new RouteEdge($id, $from, $to, $trackIndex, $length, $points, $corridors);
        $this->adjacency[$from][] = $id;
        $this->adjacency[$to][] = $id;
    }
}
