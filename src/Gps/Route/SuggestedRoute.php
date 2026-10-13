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
