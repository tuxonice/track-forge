<?php

namespace App\Service;

use App\Entity\Track;
use App\Exception\RouteSuggestionException;
use App\Gps\GpsTrack;
use App\Gps\Route\CrossingDetector;
use App\Gps\Route\PlanarTrack;
use App\Gps\Route\RouteFinder;
use App\Gps\Route\RoutePoint;
use App\Gps\Route\SuggestedRoute;
use App\Gps\Route\TrackGraphBuilder;

class RouteSuggestionService
{
    private const MAX_POINTS_PER_TRACK = 20000;

    public function __construct(
        private readonly GpsTrack $gpsTrack,
        private readonly FileUploaderService $fileUploaderService,
        private readonly CrossingDetector $crossingDetector,
        private readonly TrackGraphBuilder $graphBuilder,
        private readonly RouteFinder $routeFinder,
        private readonly int $routeMaxTracks,
    ) {
    }

    /**
     * Ranked route suggestions (longest first) stitched from the given tracks; empty when no
     * route rides every track.
     *
     * @param list<Track> $tracks
     * @return list<SuggestedRoute>
     */
    public function suggest(array $tracks): array
    {
        $tracks = array_values($tracks);
        if (count($tracks) < 2) {
            throw new RouteSuggestionException('route.error.too_few_tracks');
        }
        if (count($tracks) > $this->routeMaxTracks) {
            throw new RouteSuggestionException('route.error.too_many_tracks', ['%max%' => $this->routeMaxTracks]);
        }

        $pointLists = array_map(fn (Track $track): array => $this->loadPoints($track), $tracks);

        $latitudes = [];
        foreach ($pointLists as $points) {
            foreach ($points as $point) {
                $latitudes[] = $point->lat;
            }
        }
        $originLat = $latitudes === [] ? 0.0 : array_sum($latitudes) / count($latitudes);

        $planar = [];
        foreach ($pointLists as $i => $points) {
            try {
                $planar[] = new PlanarTrack($points, $originLat);
            } catch (\InvalidArgumentException) {
                throw new RouteSuggestionException('route.error.track_too_short', ['%name%' => $tracks[$i]->getName()]);
            }
        }

        $crossings = $this->crossingDetector->detect($planar);

        return $this->routeFinder->find($this->graphBuilder->build($planar, $crossings));
    }

    /** @return list<RoutePoint> */
    private function loadPoints(Track $track): array
    {
        $path = $this->fileUploaderService->getUserDataPath($track->getUser()) . '/' . $track->getFilename();
        if (!is_file($path)) {
            throw new RouteSuggestionException('route.error.track_file_missing', ['%name%' => $track->getName()]);
        }

        try {
            // phpGPX only emits PHP warnings for malformed XML and carries on with an empty file,
            // so check well-formedness up front to report a corrupt file reliably.
            if (!$this->isWellFormedXml($path)) {
                throw new \RuntimeException('Malformed XML');
            }
            $this->gpsTrack->process($path);
            $points = $this->gpsTrack->getRawPoints();
        } catch (\Throwable) {
            throw new RouteSuggestionException('route.error.track_unreadable', ['%name%' => $track->getName()]);
        }
        if (count($points) > self::MAX_POINTS_PER_TRACK) {
            throw new RouteSuggestionException('route.error.track_too_large', [
                '%name%' => $track->getName(),
                '%max%' => self::MAX_POINTS_PER_TRACK,
            ]);
        }

        return $points;
    }

    private function isWellFormedXml(string $path): bool
    {
        $previous = libxml_use_internal_errors(true);
        try {
            return simplexml_load_file($path) !== false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
