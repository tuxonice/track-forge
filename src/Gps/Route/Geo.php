<?php

namespace App\Gps\Route;

final class Geo
{
    public const EARTH_RADIUS = 6371e3;

    // From https://www.movable-type.co.uk/scripts/latlong.html (same formula as GpsTrack).
    public static function distance(RoutePoint $start, RoutePoint $end): float
    {
        $fi1 = deg2rad($start->lat);
        $fi2 = deg2rad($end->lat);
        $deltaFi = deg2rad($end->lat - $start->lat);
        $deltaLambda = deg2rad($end->lon - $start->lon);

        $a = sin($deltaFi / 2) ** 2 + cos($fi1) * cos($fi2) * sin($deltaLambda / 2) ** 2;

        return self::EARTH_RADIUS * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
