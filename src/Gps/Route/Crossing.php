<?php

namespace App\Gps\Route;

/**
 * A place where track A meets track B. The entry and exit crossings of one stretch the two tracks
 * ride together share a $stretchId (null for a plain crossing or touch).
 */
final readonly class Crossing
{
    public function __construct(
        public int $trackA,
        public int $trackB,
        public float $distanceA,
        public float $distanceB,
        public float $gap,
        public ?int $stretchId = null,
    ) {
    }
}
