<?php

namespace App\Gps\Route;

/**
 * A piece of one track between two nodes, or (trackIndex null) a connector between two tracks.
 * A piece lying on a stretch two tracks ride together lists that stretch's id in $corridors.
 */
final readonly class RouteEdge
{
    /**
     * @param list<RoutePoint> $points ordered from `from` to `to`
     * @param list<int> $corridors ids of the shared stretches this piece lies on
     */
    public function __construct(
        public int $id,
        public int $from,
        public int $to,
        public ?int $trackIndex,
        public float $length,
        public array $points,
        public array $corridors = [],
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
