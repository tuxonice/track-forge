<?php

namespace App\Gps\Route;

/**
 * Splits each track at its crossings. Nodes are locations on a track (its two endpoints and every
 * crossing); edges are the track pieces between consecutive nodes, plus a connector edge for each
 * crossing linking the two tracks' nodes.
 *
 * The pieces of both tracks between the entry and exit crossings of a shared stretch are tagged
 * with the stretch's id (a "corridor"), so the route finder can tell they are the same ground.
 */
final class TrackGraphBuilder
{
    /** Cuts closer than this (metres) are the same node; cuts this close to a track end snap to it. */
    private const SNAP = 1.0;

    /**
     * @param list<PlanarTrack> $tracks
     * @param list<Crossing> $crossings
     */
    public function build(array $tracks, array $crossings): TrackGraph
    {
        /** @var array<int, list<float>> $cuts */
        $cuts = [];
        foreach ($tracks as $index => $track) {
            $raw = [0.0, $track->length()];
            foreach ($crossings as $crossing) {
                if ($crossing->trackA === $index) {
                    $raw[] = $crossing->distanceA;
                }
                if ($crossing->trackB === $index) {
                    $raw[] = $crossing->distanceB;
                }
            }
            $cuts[$index] = $this->mergeCuts($raw, $track->length());
        }

        $graph = new TrackGraph(count($tracks));
        /** @var array<int, array<int, int>> $nodeIds track index => cut index => node id */
        $nodeIds = [];
        foreach ($cuts as $index => $list) {
            foreach ($list as $k => $distance) {
                $nodeIds[$index][$k] = $graph->addNode($index, $distance, $k === 0 || $k === count($list) - 1);
            }
        }

        $corridors = $this->corridors($crossings, $cuts);
        foreach ($cuts as $index => $list) {
            for ($k = 0; $k + 1 < count($list); $k++) {
                $graph->addEdge(
                    $nodeIds[$index][$k],
                    $nodeIds[$index][$k + 1],
                    $index,
                    $list[$k + 1] - $list[$k],
                    $tracks[$index]->slice($list[$k], $list[$k + 1]),
                    $corridors[$index][$k] ?? []
                );
            }
        }

        $linked = [];
        foreach ($crossings as $crossing) {
            $from = $nodeIds[$crossing->trackA][$this->nearestCut($cuts[$crossing->trackA], $crossing->distanceA)];
            $to = $nodeIds[$crossing->trackB][$this->nearestCut($cuts[$crossing->trackB], $crossing->distanceB)];
            $key = min($from, $to) . '-' . max($from, $to);
            if (isset($linked[$key])) {
                continue;
            }
            $linked[$key] = true;

            $fromPoint = $tracks[$crossing->trackA]->pointAt($graph->nodes[$from]->distance);
            $toPoint = $tracks[$crossing->trackB]->pointAt($graph->nodes[$to]->distance);
            $graph->addEdge($from, $to, null, Geo::distance($fromPoint, $toPoint), [$fromPoint, $toPoint]);
        }

        return $graph;
    }

    /**
     * Shared-stretch ids per piece: every piece of either track lying between the cuts of a
     * stretch's entry and exit crossings.
     *
     * @param list<Crossing> $crossings
     * @param array<int, list<float>> $cuts
     * @return array<int, array<int, list<int>>> track index => piece index => stretch ids
     */
    private function corridors(array $crossings, array $cuts): array
    {
        /** @var array<int, list<Crossing>> $stretches */
        $stretches = [];
        foreach ($crossings as $crossing) {
            if ($crossing->stretchId !== null) {
                $stretches[$crossing->stretchId][] = $crossing;
            }
        }

        $corridors = [];
        foreach ($stretches as $stretchId => $ends) {
            if (count($ends) !== 2) {
                continue;
            }
            $sides = [
                $ends[0]->trackA => [$ends[0]->distanceA, $ends[1]->distanceA],
                $ends[0]->trackB => [$ends[0]->distanceB, $ends[1]->distanceB],
            ];
            foreach ($sides as $track => [$one, $other]) {
                $first = $this->nearestCut($cuts[$track], min($one, $other));
                $last = $this->nearestCut($cuts[$track], max($one, $other));
                for ($k = $first; $k < $last; $k++) {
                    $corridors[$track][$k][] = $stretchId;
                }
            }
        }

        return $corridors;
    }

    /**
     * @param list<float> $raw
     * @return list<float> ascending, starting at 0.0 and ending at $length
     */
    private function mergeCuts(array $raw, float $length): array
    {
        sort($raw);
        $merged = [];
        foreach ($raw as $distance) {
            if ($merged !== [] && $distance - $merged[count($merged) - 1] <= self::SNAP) {
                continue;
            }
            $merged[] = $distance;
        }

        $last = count($merged) - 1;
        if ($last > 0 && $length - $merged[$last] <= self::SNAP) {
            $merged[$last] = $length;
        } elseif ($merged[$last] < $length) {
            $merged[] = $length;
        }

        return $merged;
    }

    /** @param list<float> $cuts */
    private function nearestCut(array $cuts, float $distance): int
    {
        $best = 0;
        foreach ($cuts as $k => $cut) {
            if (abs($cut - $distance) < abs($cuts[$best] - $distance)) {
                $best = $k;
            }
        }

        return $best;
    }
}
