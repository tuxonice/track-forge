<?php

namespace App\Controller;

use App\Gps\GpsTrack;
use App\Repository\TrackRepository;
use App\Service\FileUploaderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class MapController extends AbstractController
{
    public function __construct(
        private readonly TrackRepository $trackRepository,
        private readonly FileUploaderService $fileUploaderService,
        private readonly GpsTrack $gpsTrack,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: ['en' => '/en/map/{trackKey}', 'pt' => '/pt/mapa/{trackKey}'], name: 'app_track_map', methods: ['GET'])]
    public function index(string $trackKey): Response
    {
        $track = $this->trackRepository->findOneByKey($trackKey);
        if (!$track) {
            $this->addFlash('danger', $this->translator->trans('flash.track_file_not_found'));

            return $this->redirectToRoute('app_home');
        }

        if ($track->getUser() !== $this->getUser()) {
            $this->addFlash('danger', $this->translator->trans('flash.track_not_found'));

            return $this->redirectToRoute('app_profile');
        }

        $trackFilePath = $this->fileUploaderService->getUserDataPath($track->getUser()) . '/' . $track->getFilename();
        if (!file_exists($trackFilePath)) {
            $this->addFlash('danger', $this->translator->trans('flash.track_file_not_found'));

            return $this->redirectToRoute('app_profile');
        }

        $this->gpsTrack->process($trackFilePath);

        return $this->render('Default/map.html.twig', [
            'title' => $track->getName(),
            'points' => $this->gpsTrack->getJsonPoints(),
            'info' => $this->gpsTrack->getInfo(),
        ]);
    }

    #[Route(path: ['en' => '/en/track/view/{trackKey}', 'pt' => '/pt/percurso/ver/{trackKey}'], name: 'app_track_view', methods: ['GET'])]
    public function view(string $trackKey): Response
    {
        $track = $this->trackRepository->findOneByKey($trackKey);
        if (!$track) {
            $this->addFlash('danger', $this->translator->trans('flash.track_file_not_found'));

            return $this->redirectToRoute('app_home');
        }

        if ($track->getUser() !== $this->getUser()) {
            $this->addFlash('danger', $this->translator->trans('flash.track_not_found'));

            return $this->redirectToRoute('app_profile');
        }

        $trackFilePath = $this->fileUploaderService->getUserDataPath($track->getUser()) . '/' . $track->getFilename();
        if (!file_exists($trackFilePath)) {
            $this->addFlash('danger', $this->translator->trans('flash.track_file_not_found'));

            return $this->redirectToRoute('app_profile');
        }

        $this->gpsTrack->process($trackFilePath);

        return $this->render('Default/track-view.html.twig', [
            'track' => $track,
            'points' => $this->gpsTrack->getJsonPoints(),
            'showVelocityChart' => $this->gpsTrack->hasTrackPoints() && $this->gpsTrack->hasVelocityData(),
        ]);
    }
}
