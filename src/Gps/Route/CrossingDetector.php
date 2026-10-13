<?php

namespace App\Gps\Route;

/**
 * Finds where tracks cross or come within a tolerance of each other. For every segment of track
 * A it finds the closest approach to track B; consecutive segments in contact form a "run". A
 * short run (a crossing, a touch) becomes one crossing at its closest point; a long run (a stretch
 * ridden together) becomes an entry and an exit crossing sharing a stretch id.
 *
 * A spatial grid (cells one tolerance wide) keeps that fast: each segment of B is filed under the
 * cells it actually passes through (walked cell by cell, not its bounding box), and each segment of
 * A only looks in the cells it passes through plus their neighbours, so the work grows with the
 * length of the tracks rather than with the square of any one segment. Segments longer than
 * LONG_SEGMENT_CELLS cells (a stray GPS point far away, a long gap in a recording) stay out of the
 * grid and are compared directly, so a single glitch cannot blow up the grid.
 */
final class CrossingDetector
{
    /** Runs spanning less than this many tolerances along A count as a single crossing. */
    private const SHORT_RUN_TOLERANCES = 4;
    /** Segments passing through more grid cells than this are compared directly instead of gridded. */
    private const LONG_SEGMENT_CELLS = 1000;
    /** Packs a cell (x, y) into one integer key; |y| in cells stays far below this at any latitude. */
    private const ROW = 100000000;

    private int $nextStretchId = 0;

    public function __construct(private readonly float $routeCrossingToleranceM)
    {
    }

    /**
     * @param list<PlanarTrack> $tracks
     * @return list<Crossing>
     */
    public function detect(array $tracks): array
    {
        $this->nextStretchId = 0;
        $cellSize = max(1.0, $this->routeCrossingToleranceM);

        /** @var array<int, array{grid: array<int, list<int>>, long: list<int>}> $indexes built once per track */
        $indexes = [];
        $crossings = [];
        $count = count($tracks);
        for ($a = 0; $a < $count; $a++) {
            for ($b = $a + 1; $b < $count; $b++) {
                $indexes[$b] ??= $this->index($tracks[$b], $cellSize);
                foreach ($this->detectPair($tracks[$a], $tracks[$b], $indexes[$b], $a, $b, $cellSize) as $crossing) {
                    $crossings[] = $crossing;
                }
            }
        }

        return $crossings;
    }

    /**
     * @param array{grid: array<int, list<int>>, long: list<int>} $index of $b
     * @return list<Crossing>
     */
    private function detectPair(PlanarTrack $a, PlanarTrack $b, array $index, int $indexA, int $indexB, float $cellSize): array
    {
        $tolerance = $this->routeCrossingToleranceM;
        $reach = (int) ceil($tolerance / $cellSize);
        $ax = $a->xs();
        $ay = $a->ys();
        $bx = $b->xs();
        $by = $b->ys();
        $grid = $index['grid'];
        $allOfB = null;
        $lastOfB = $b->segmentCount() - 1;
        $previous = null;

        /** @var array<int, array{gap: float, a: float, b: float}> $contacts keyed by segment of A */
        $contacts = [];
        $segments = $a->segmentCount();
        for ($i = 0; $i < $segments; $i++) {
            $x0 = $ax[$i];
            $y0 = $ay[$i];
            $x1 = $ax[$i + 1];
            $y1 = $ay[$i + 1];

            // Lists of candidate segments of B; one may appear twice (it spans two cells), which is harmless.
            if ($this->cellSpan($x0, $y0, $x1, $y1, $cellSize) > self::LONG_SEGMENT_CELLS) {
                $lists = [$allOfB ??= range(0, $lastOfB)];
            } else {
                $lists = [$index['long']];
                foreach ($this->cellsAlong($x0, $y0, $x1, $y1, $cellSize) as [$cx, $cy]) {
                    for ($nx = $cx - $reach; $nx <= $cx + $reach; $nx++) {
                        for ($ny = $cy - $reach; $ny <= $cy + $reach; $ny++) {
                            if (isset($grid[$nx * self::ROW + $ny])) {
                                $lists[] = $grid[$nx * self::ROW + $ny];
                            }
                        }
                    }
                }
            }

            $minX = min($x0, $x1);
            $maxX = max($x0, $x1);
            $minY = min($y0, $y1);
            $maxY = max($y0, $y1);
            $best = null;
            $limit = $tolerance;
            // Along a shared stretch the nearest segment of B is next to the previous one: trying
            // those first tightens $limit early so most other candidates fail the cheap box test.
            if ($previous !== null) {
                $lists = [range(max(0, $previous - 2), min($lastOfB, $previous + 2)), ...$lists];
            }
            foreach ($lists as $candidates) {
                foreach ($candidates as $j) {
                    $bx0 = $bx[$j];
                    $bx1 = $bx[$j + 1];
                    $by0 = $by[$j];
                    $by1 = $by[$j + 1];
                    // Bounding boxes farther apart than the best gap so far cannot do better.
                    if (
                        ($bx0 < $bx1 ? $bx0 : $bx1) - $maxX > $limit || $minX - ($bx0 > $bx1 ? $bx0 : $bx1) > $limit
                        || ($by0 < $by1 ? $by0 : $by1) - $maxY > $limit || $minY - ($by0 > $by1 ? $by0 : $by1) > $limit
                    ) {
                        continue;
                    }
                    [$gap, $t, $u] = $this->closest($x0, $y0, $x1 - $x0, $y1 - $y0, $bx0, $by0, $bx1 - $bx0, $by1 - $by0);
                    if ($gap <= $tolerance && ($best === null || $gap < $best[0])) {
                        $best = [$gap, $t, $j, $u];
                        $limit = $gap;
                        if ($gap === 0.0) {
                            break 2; // They intersect: nothing is closer.
                        }
                    }
                }
            }
            $previous = $best === null ? null : $best[2];
            if ($best !== null) {
                $contacts[$i] = ['gap' => $best[0], 'a' => $a->positionAt($i, $best[1]), 'b' => $b->positionAt($best[2], $best[3])];
            }
        }

        return $this->crossingsFromContacts($contacts, $a, $indexA, $indexB);
    }

    /**
     * @param array<int, array{gap: float, a: float, b: float}> $contacts
     * @return list<Crossing>
     */
    private function crossingsFromContacts(array $contacts, PlanarTrack $a, int $indexA, int $indexB): array
    {
        /** @var list<list<array{gap: float, a: float, b: float}>> $runs */
        $runs = [];
        $run = [];
        $previousSegment = null;
        $previous = null;
        foreach ($contacts as $segment => $contact) {
            if (
                $previous !== null && $previousSegment !== null
                && ($segment !== $previousSegment + 1
                    || abs($contact['b'] - $previous['b']) > self::SHORT_RUN_TOLERANCES * $this->routeCrossingToleranceM + $a->segmentLength($previousSegment))
            ) {
                $runs[] = $run;
                $run = [];
            }
            $run[] = $contact;
            $previousSegment = $segment;
            $previous = $contact;
        }
        if ($run !== []) {
            $runs[] = $run;
        }

        $crossings = [];
        foreach ($runs as $run) {
            $first = $run[0];
            $last = $run[count($run) - 1];
            if (count($run) === 1 || $last['a'] - $first['a'] < self::SHORT_RUN_TOLERANCES * $this->routeCrossingToleranceM) {
                $closest = $first;
                foreach ($run as $contact) {
                    if ($contact['gap'] < $closest['gap']) {
                        $closest = $contact;
                    }
                }
                $crossings[] = new Crossing($indexA, $indexB, $closest['a'], $closest['b'], $closest['gap']);
                continue;
            }
            $stretchId = $this->nextStretchId++;
            $crossings[] = new Crossing($indexA, $indexB, $first['a'], $first['b'], $first['gap'], $stretchId);
            $crossings[] = new Crossing($indexA, $indexB, $last['a'], $last['b'], $last['gap'], $stretchId);
        }

        return $crossings;
    }

    /**
     * Segments of $track filed under every grid cell they pass through; over-long segments listed apart.
     *
     * @return array{grid: array<int, list<int>>, long: list<int>}
     */
    private function index(PlanarTrack $track, float $cellSize): array
    {
        $xs = $track->xs();
        $ys = $track->ys();
        $grid = [];
        $long = [];
        $segments = $track->segmentCount();
        for ($j = 0; $j < $segments; $j++) {
            if ($this->cellSpan($xs[$j], $ys[$j], $xs[$j + 1], $ys[$j + 1], $cellSize) > self::LONG_SEGMENT_CELLS) {
                $long[] = $j;
                continue;
            }
            foreach ($this->cellsAlong($xs[$j], $ys[$j], $xs[$j + 1], $ys[$j + 1], $cellSize) as [$cx, $cy]) {
                $grid[$cx * self::ROW + $cy][] = $j;
            }
        }

        return ['grid' => $grid, 'long' => $long];
    }

    /** How many cells the segment passes through, without listing them. */
    private function cellSpan(float $x0, float $y0, float $x1, float $y1, float $cellSize): int
    {
        return (int) (abs(floor($x1 / $cellSize) - floor($x0 / $cellSize)) + abs(floor($y1 / $cellSize) - floor($y0 / $cellSize))) + 1;
    }

    /**
     * The grid cells a segment passes through, in order, found by stepping from cell boundary to
     * cell boundary along it (Amanatides & Woo); the cost is the number of cells, not their area.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function cellsAlong(float $x0, float $y0, float $x1, float $y1, float $cellSize): array
    {
        $cx = (int) floor($x0 / $cellSize);
        $cy = (int) floor($y0 / $cellSize);
        $ex = (int) floor($x1 / $cellSize);
        $ey = (int) floor($y1 / $cellSize);
        $cells = [[$cx, $cy]];
        $steps = abs($ex - $cx) + abs($ey - $cy);
        if ($steps === 0) {
            return $cells;
        }

        $dx = $x1 - $x0;
        $dy = $y1 - $y0;
        $stepX = $ex <=> $cx;
        $stepY = $ey <=> $cy;
        // Parameter (0..1 along the segment) of the next vertical / horizontal cell boundary.
        $nextX = $stepX === 0 ? INF : ((($stepX > 0 ? $cx + 1 : $cx) * $cellSize) - $x0) / $dx;
        $nextY = $stepY === 0 ? INF : ((($stepY > 0 ? $cy + 1 : $cy) * $cellSize) - $y0) / $dy;
        $deltaX = $stepX === 0 ? INF : $cellSize / abs($dx);
        $deltaY = $stepY === 0 ? INF : $cellSize / abs($dy);

        for ($k = 0; $k < $steps; $k++) {
            if ($cy === $ey || ($cx !== $ex && $nextX < $nextY)) {
                $cx += $stepX;
                $nextX += $deltaX;
            } else {
                $cy += $stepY;
                $nextY += $deltaY;
            }
            $cells[] = [$cx, $cy];
        }

        return $cells;
    }

    /**
     * Closest approach between segment (ax,ay)+t(rx,ry) and segment (bx,by)+u(sx,sy).
     *
     * @return array{0: float, 1: float, 2: float} distance in metres, parameter t (0..1), parameter u
     */
    private function closest(float $ax, float $ay, float $rx, float $ry, float $bx, float $by, float $sx, float $sy): array
    {
        $denominator = $rx * $sy - $ry * $sx;
        if (abs($denominator) > 1e-9) {
            $qx = $bx - $ax;
            $qy = $by - $ay;
            $t = ($qx * $sy - $qy * $sx) / $denominator;
            $u = ($qx * $ry - $qy * $rx) / $denominator;
            if ($t >= 0.0 && $t <= 1.0 && $u >= 0.0 && $u <= 1.0) {
                return [0.0, $t, $u];
            }
        }

        // No intersection: the closest approach involves an endpoint of one of the segments.
        $best = [INF, 0.0, 0.0];
        foreach ([0.0, 1.0] as $t) {
            $px = $ax + $t * $rx;
            $py = $ay + $t * $ry;
            $u = $this->project($px, $py, $bx, $by, $sx, $sy);
            $gap = hypot($px - ($bx + $u * $sx), $py - ($by + $u * $sy));
            if ($gap < $best[0]) {
                $best = [$gap, $t, $u];
            }
        }
        foreach ([0.0, 1.0] as $u) {
            $px = $bx + $u * $sx;
            $py = $by + $u * $sy;
            $t = $this->project($px, $py, $ax, $ay, $rx, $ry);
            $gap = hypot($px - ($ax + $t * $rx), $py - ($ay + $t * $ry));
            if ($gap < $best[0]) {
                $best = [$gap, $t, $u];
            }
        }

        return $best;
    }

    /** Parameter (clamped to 0..1) of the point on segment (x1,y1)+t(dx,dy) closest to (px,py). */
    private function project(float $px, float $py, float $x1, float $y1, float $dx, float $dy): float
    {
        $lengthSquared = $dx * $dx + $dy * $dy;
        if ($lengthSquared <= 0.0) {
            return 0.0;
        }

        return min(1.0, max(0.0, (($px - $x1) * $dx + ($py - $y1) * $dy) / $lengthSquared));
    }
}
