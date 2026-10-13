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
