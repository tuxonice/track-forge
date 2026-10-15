<?php

namespace App\Gps\Route;

final readonly class RouteSuggestion
{
    /**
     * @param SuggestedRoute|null $route the longest route riding every source track, null when there is none
     * @param list<list<RoutePoint>> $sourcePoints the points of each source track, in selection order
     */
    public function __construct(
        public ?SuggestedRoute $route,
        public array $sourcePoints,
    ) {
    }
}
