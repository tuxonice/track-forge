<?php

namespace App\Gps\Route;

/**
 * A track as a polyline: its points, their planar metre coordinates (equirectangular around a
 * shared origin latitude, good enough for the few-km areas tracks cover) and the cumulative
 * haversine distance at each point. A "position" is a distance in metres along the track.
 *
 * Known limitation: longitudes are not wrapped, so a track crossing the antimeridian (+-180 deg)
 * is treated as jumping all the way round the globe.
 */
final class PlanarTrack
{
    /** @var list<RoutePoint> */
    private array $points = [];
    /** @var list<float> */
    private array $xs = [];
    /** @var list<float> */
    private array $ys = [];
    /** @var list<float> */
    private array $cumulative = [];

    /** @param list<RoutePoint> $points */
    public function __construct(array $points, float $originLat)
    {
        $cosLat = cos(deg2rad($originLat));

        foreach ($points as $point) {
            $last = $this->points[count($this->points) - 1] ?? null;
            if ($last !== null && $last->lat === $point->lat && $last->lon === $point->lon) {
                continue;
            }

            $this->cumulative[] = $last === null
                ? 0.0
                : $this->cumulative[count($this->cumulative) - 1] + Geo::distance($last, $point);
            $this->xs[] = deg2rad($point->lon) * $cosLat * Geo::EARTH_RADIUS;
            $this->ys[] = deg2rad($point->lat) * Geo::EARTH_RADIUS;
            $this->points[] = $point;
        }

        if (count($this->points) < 2) {
            throw new \InvalidArgumentException('A track needs at least two distinct points');
        }
    }

    public function segmentCount(): int
    {
        return count($this->points) - 1;
    }

    public function length(): float
    {
        return $this->cumulative[count($this->cumulative) - 1];
    }

    public function segmentLength(int $segment): float
    {
        return $this->cumulative[$segment + 1] - $this->cumulative[$segment];
    }

    public function x(int $i): float
    {
        return $this->xs[$i];
    }

    public function y(int $i): float
    {
        return $this->ys[$i];
    }

    /** @return list<float> planar x of every point, for loops too hot for one call per point */
    public function xs(): array
    {
        return $this->xs;
    }

    /** @return list<float> planar y of every point */
    public function ys(): array
    {
        return $this->ys;
    }

    /** Distance along the track of the point $t (0..1) of the way along $segment. */
    public function positionAt(int $segment, float $t): float
    {
        return $this->cumulative[$segment] + $t * $this->segmentLength($segment);
    }

    public function pointAt(float $distance): RoutePoint
    {
        $distance = min($this->length(), max(0.0, $distance));

        return $this->interpolate($this->segmentAt($distance), $distance);
    }

    /**
     * The points between two distances: an interpolated start, every original point strictly
     * between them, an interpolated end.
     *
     * @return list<RoutePoint>
     */
    public function slice(float $from, float $to): array
    {
        $from = max(0.0, $from);
        $to = min($this->length(), $to);

        $result = [$this->pointAt($from)];
        foreach ($this->points as $i => $point) {
            if ($this->cumulative[$i] > $from && $this->cumulative[$i] < $to) {
                $result[] = $point;
            }
        }
        $result[] = $this->pointAt($to);

        return $result;
    }

    /** The last segment whose start is at or before $distance. */
    private function segmentAt(float $distance): int
    {
        $low = 0;
        $high = $this->segmentCount() - 1;
        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            if ($this->cumulative[$mid] <= $distance) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return $low;
    }

    private function interpolate(int $segment, float $distance): RoutePoint
    {
        $start = $this->points[$segment];
        $end = $this->points[$segment + 1];
        $span = $this->segmentLength($segment);
        $t = $span > 0.0 ? min(1.0, max(0.0, ($distance - $this->cumulative[$segment]) / $span)) : 0.0;

        return new RoutePoint(
            $start->lat + ($end->lat - $start->lat) * $t,
            $start->lon + ($end->lon - $start->lon) * $t,
            $start->ele + ($end->ele - $start->ele) * $t,
        );
    }
}
