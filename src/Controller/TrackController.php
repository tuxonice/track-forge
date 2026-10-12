<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\TrackRepository;
use App\Service\FileUploaderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class TrackController extends AbstractController
{
    public function __construct(
        private readonly TrackRepository $trackRepository,
        private readonly FileUploaderService $fileUploaderService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(path: ['en' => '/en/track/info/{trackKey}', 'pt' => '/pt/percurso/info/{trackKey}'], name: 'app_track_info', methods: ['GET'])]
    public function index(string $trackKey): Response
    {
        $track = $this->trackRepository->findOneByKey($trackKey);
        if (!$track || $track->getUser() !== $this->getUser()) {
            $this->addFlash('danger', $this->translator->trans('flash.track_not_found'));

            return $this->redirectToRoute('app_tracks');
        }

        return $this->render('Default/track.html.twig', ['track' => $track]);
    }

    #[Route('/track/delete', name: 'app_track_delete', methods: ['POST'])]
    public function deleteTrack(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('track-delete', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        /** @var User $user */
        $user = $this->getUser();
        $locale = $user->getLocale();

        $trackKey = (string) $request->request->get('track_key');
        $track = $this->trackRepository->findOneByKey($trackKey);

        if (!$track || $track->getUser() !== $user) {
            $this->addFlash('danger', $this->translator->trans('flash.track_not_found', [], null, $locale));

            return $this->redirectToRoute('app_tracks', ['_locale' => $locale]);
        }

        $trackFilePath = $this->fileUploaderService->getUserDataPath($track->getUser()) . '/' . $track->getFilename();
        if (file_exists($trackFilePath)) {
            unlink($trackFilePath);
        }

        $this->entityManager->remove($track);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('flash.track_deleted', [], null, $locale));

        return $this->redirectToRoute('app_tracks', ['_locale' => $locale]);
    }
}
