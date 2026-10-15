<?php

namespace App\Controller;

use App\Gps\GpsTrack;
use App\Entity\Track;
use App\Repository\TrackRepository;
use App\Service\FileUploaderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class MapController extends AbstractController
{
    private const MAX_MAP_TRACKS = 3;
    private const TRACK_COLORS = ['#ff7a00', '#1f78ff', '#1a9850'];

    public function __construct(
        private readonly TrackRepository $trackRepository,
        private readonly FileUploaderService $fileUploaderService,
        private readonly GpsTrack $gpsTrack,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: ['en' => '/en/map', 'pt' => '/pt/mapa'], name: 'app_tracks_map', methods: ['GET'])]
    public function multi(Request $request): Response
    {
        $keys = array_values(array_unique(array_filter(
            explode(',', $request->query->getString('tracks')),
            static fn (string $key): bool => $key !== ''
        )));

        if ($keys === []) {
            $this->addFlash('danger', $this->translator->trans('flash.no_tracks_selected'));

            return $this->redirectToRoute('app_tracks');
        }

        if (count($keys) > self::MAX_MAP_TRACKS) {
            $this->addFlash('danger', $this->translator->trans('flash.too_many_tracks', ['%max%' => self::MAX_MAP_TRACKS]));

            return $this->redirectToRoute('app_tracks');
        }

        $mapTracks = [];
        foreach ($keys as $index => $key) {
            $track = $this->findTrack($key);
            if ($track instanceof Response) {
                return $track;
            }

            $this->gpsTrack->process($this->trackFilePath($track));
            $info = $this->gpsTrack->getInfo();

            $mapTracks[] = [
                'name' => $track->getName(),
                'color' => self::TRACK_COLORS[$index],
                'distance' => $info['totalDistance'],
                'latlngs' => array_map(
                    static fn (array $point): array => [$point['latitude'], $point['longitude']],
                    $this->gpsTrack->getPoints()
                ),
            ];
        }

        return $this->render('Default/map-multi.html.twig', [
            'title' => implode(' / ', array_column($mapTracks, 'name')),
            'tracks' => $mapTracks,
        ]);
    }

    #[Route(path: ['en' => '/en/map/{trackKey}', 'pt' => '/pt/mapa/{trackKey}'], name: 'app_track_map', methods: ['GET'])]
    public function index(string $trackKey): Response
    {
        $track = $this->findTrack($trackKey);
        if ($track instanceof Response) {
            return $track;
        }

        $this->gpsTrack->process($this->trackFilePath($track));

        return $this->render('Default/map.html.twig', [
            'title' => $track->getName(),
            'points' => $this->gpsTrack->getJsonPoints(),
            'info' => $this->gpsTrack->getInfo(),
        ]);
    }

    #[Route(path: ['en' => '/en/track/view/{trackKey}', 'pt' => '/pt/percurso/ver/{trackKey}'], name: 'app_track_view', methods: ['GET'])]
    public function view(string $trackKey): Response
    {
        $track = $this->findTrack($trackKey);
        if ($track instanceof Response) {
            return $track;
        }

        $this->gpsTrack->process($this->trackFilePath($track));

        return $this->render('Default/track-view.html.twig', [
            'track' => $track,
            'points' => $this->gpsTrack->getJsonPoints(),
            'showVelocityChart' => $this->gpsTrack->hasTrackPoints() && $this->gpsTrack->hasVelocityData(),
        ]);
    }

    /** The current user's track for the key, or a redirect response (with a flash) when it can't be shown. */
    private function findTrack(string $trackKey): Track|Response
    {
        $track = $this->trackRepository->findOneByKey($trackKey);
        if (!$track) {
            $this->addFlash('danger', $this->translator->trans('flash.track_file_not_found'));

            return $this->redirectToRoute('app_home');
        }

        if ($track->getUser() !== $this->getUser()) {
            $this->addFlash('danger', $this->translator->trans('flash.track_not_found'));

            return $this->redirectToRoute('app_tracks');
        }

        if (!file_exists($this->trackFilePath($track))) {
            $this->addFlash('danger', $this->translator->trans('flash.track_file_not_found'));

            return $this->redirectToRoute('app_tracks');
        }

        return $track;
    }

    private function trackFilePath(Track $track): string
    {
        return $this->fileUploaderService->getUserDataPath($track->getUser()) . '/' . $track->getFilename();
    }
}
