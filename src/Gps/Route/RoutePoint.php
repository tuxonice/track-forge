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
