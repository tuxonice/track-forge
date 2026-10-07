<?php

namespace App\Controller;

use App\Entity\User;
use App\Pagination\Paginator;
use App\Repository\TrackRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class HomeController extends AbstractController
{
    public function __construct(
        private readonly TrackRepository $trackRepository,
        private readonly TranslatorInterface $translator,
        private readonly int $paginationIpp,
    ) {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(Request $request): RedirectResponse
    {
        $locale = $request->getPreferredLanguage(['en', 'pt']);

        return $this->redirectToRoute('app_profile', ['_locale' => $locale]);
    }

    #[Route(path: ['en' => '/en/tracks/', 'pt' => '/pt/percursos/'], name: 'app_profile', methods: ['GET'])]
    #[Route(path: ['en' => '/en/tracks/{page}', 'pt' => '/pt/percursos/{page}'], name: 'app_profile_page', requirements: ['page' => '\d+'], methods: ['GET'])]
    public function profile(Request $request): Response
    {
        $page = (int) ($request->attributes->get('page') ?? $request->query->get('page', 1));
        $page = max(1, $page);

        /** @var User $user */
        $user = $this->getUser();

        $total = $this->trackRepository->countForUser($user);
        $tracks = $this->trackRepository->findPageForUser($user, ($page - 1) * $this->paginationIpp, $this->paginationIpp);

        $paginator = new Paginator($request->getPathInfo(), ['page' => $page, 'ipp' => $this->paginationIpp]);
        $paginator->setItemsTotal($total);
        $paginator->setMidRange(3);
        $paginator->setLabels(
            $this->translator->trans('home.pagination_previous'),
            $this->translator->trans('home.pagination_next')
        );
        $paginator->paginate();

        return $this->render('Default/home.html.twig', [
            'tracks' => $tracks,
            'pages' => $paginator->displayPages(),
            'totalTracks' => $total,
            'totalDistance' => $this->trackRepository->sumDistanceForUser($user),
            'totalElevation' => $this->trackRepository->sumElevationForUser($user),
        ]);
    }
}
