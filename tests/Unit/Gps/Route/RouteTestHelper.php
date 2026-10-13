<?php

namespace App\Tests\Unit\Gps\Route;

use App\Gps\Route\Geo;
use App\Gps\Route\PlanarTrack;
use App\Gps\Route\RoutePoint;

final class RouteTestHelper
{
    /** @param list<array{0: float, 1: float}> $latLon [lat, lon] pairs */
    public static function track(array $latLon, float $originLat = 0.0): PlanarTrack
    {
        return new PlanarTrack(self::points($latLon), $originLat);
    }

    /**
     * A polyline through the given corners with a point every $stepM metres, like a real recording.
     *
     * @param list<array{0: float, 1: float}> $latLon [lat, lon] corners
     * @return list<RoutePoint>
     */
    public static function dense(array $latLon, float $stepM = 10.0): array
    {
        $out = [];
        for ($i = 0; $i + 1 < count($latLon); $i++) {
            [$lat1, $lon1] = $latLon[$i];
            [$lat2, $lon2] = $latLon[$i + 1];
            $length = Geo::distance(new RoutePoint($lat1, $lon1), new RoutePoint($lat2, $lon2));
            $steps = max(1, (int) ceil($length / $stepM));
            for ($k = 0; $k < $steps; $k++) {
                $t = $k / $steps;
                $out[] = new RoutePoint($lat1 + ($lat2 - $lat1) * $t, $lon1 + ($lon2 - $lon1) * $t);
            }
        }
        $last = $latLon[count($latLon) - 1];
        $out[] = new RoutePoint($last[0], $last[1]);

        return $out;
    }

    /**
     * @param list<array{0: float, 1: float}> $latLon
     * @return list<RoutePoint>
     */
    private static function points(array $latLon): array
    {
        return array_map(static fn (array $p): RoutePoint => new RoutePoint($p[0], $p[1]), $latLon);
    }
}
