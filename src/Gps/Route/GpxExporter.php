<?php

namespace App\Gps\Route;

use phpGPX\Models\GpxFile;
use phpGPX\Models\Point;
use phpGPX\Models\Segment;
use phpGPX\Models\Track;

/** Writes a suggested route as a one-track GPX 1.1 document (no timestamps: stitched pieces have no coherent timeline). */
final class GpxExporter
{
    public function export(SuggestedRoute $route, string $name): string
    {
        $segment = new Segment();
        foreach ($route->points as $routePoint) {
            $point = new Point(Point::TRACKPOINT);
            $point->latitude = $routePoint->lat;
            $point->longitude = $routePoint->lon;
            $point->elevation = $routePoint->ele;
            $segment->points[] = $point;
        }

        $track = new Track();
        $track->name = $name;
        $track->segments[] = $segment;

        $gpx = new GpxFile();
        $gpx->creator = 'TrackForge';
        $gpx->tracks[] = $track;

        return (string) $gpx->toXML()->saveXML();
    }
}
