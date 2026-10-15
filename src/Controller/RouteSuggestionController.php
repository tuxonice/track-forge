<?php

namespace App\Controller;

use App\Entity\Track;
use App\Entity\User;
use App\Exception\RouteSuggestionException;
use App\Gps\GpsTrack;
use App\Gps\Route\GpxExporter;
use App\Gps\Route\RoutePoint;
use App\Repository\TrackRepository;
use App\Service\FileUploaderService;
use App\Service\RouteSuggestionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class RouteSuggestionController extends AbstractController
{
    public function __construct(
        private readonly TrackRepository $trackRepository,
        private readonly RouteSuggestionService $routeSuggestionService,
        private readonly TranslatorInterface $translator,
        private readonly GpxExporter $gpxExporter,
        private readonly FileUploaderService $fileUploaderService,
        private readonly GpsTrack $gpsTrack,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $routeMaxTracks,
    ) {
    }

    #[Route(path: ['en' => '/en/routes/suggest', 'pt' => '/pt/rotas/sugerir'], name: 'app_route_suggest', methods: ['POST'])]
    public function suggest(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('route-suggest', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        try {
            $tracks = $this->resolveTracks($request);
            $suggestion = $this->routeSuggestionService->suggest($tracks);
        } catch (RouteSuggestionException $e) {
            $this->addFlash('danger', $this->translator->trans($e->getMessage(), $e->getParameters()));

            return $this->redirectToRoute('app_tracks');
        }

        $route = $suggestion->route;

        return $this->render('Default/route-suggestions.html.twig', [
            'route' => $route === null ? null : [
                'distanceKm' => $route->distance / 1000,
                'elevation' => $route->elevationGain(),
                'trackNames' => array_map(static fn (int $i): string => $tracks[$i]->getName(), $route->trackIndexes),
                'latLngs' => self::latLngs($route->points),
            ],
            'sources' => array_map(
                static fn (Track $track, array $points): array => [
                    'name' => $track->getName(),
                    'latLngs' => self::latLngs($points),
                ],
                $tracks,
                $suggestion->sourcePoints
            ),
            'trackKeys' => array_map(static fn (Track $track): string => $track->getKey(), $tracks),
        ]);
    }

    #[Route('/routes/save', name: 'app_route_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('route-save', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        /** @var User $user */
        $user = $this->getUser();
        $locale = $user->getLocale();

        try {
            $tracks = $this->resolveTracks($request);
            // Recompute rather than trust the client: no coordinates ever come from the request.
            $route = $this->routeSuggestionService->suggest($tracks)->route;
        } catch (RouteSuggestionException $e) {
            $this->addFlash('danger', $this->translator->trans($e->getMessage(), $e->getParameters(), null, $locale));

            return $this->redirectToRoute('app_tracks', ['_locale' => $locale]);
        }

        if ($route === null) {
            $this->addFlash('danger', $this->translator->trans('route.error.not_found', [], null, $locale));

            return $this->redirectToRoute('app_tracks', ['_locale' => $locale]);
        }

        $name = $this->translator->trans(
            'route.default_name',
            ['%date%' => (new \DateTimeImmutable())->format('Y-m-d H:i')],
            null,
            $locale
        );
        $targetFileName = uniqid() . '.gpx';
        $xml = $this->gpxExporter->export($route, $name);

        if (!$this->fileUploaderService->storeContents($user, $xml, $targetFileName)) {
            $this->addFlash('danger', $this->translator->trans('flash.route_save_failed', [], null, $locale));

            return $this->redirectToRoute('app_tracks', ['_locale' => $locale]);
        }

        $this->gpsTrack->process($this->fileUploaderService->getUserDataPath($user) . '/' . $targetFileName);
        $info = $this->gpsTrack->getInfo();

        $track = new Track($user, $name, $targetFileName);
        $track->setTotalPoints($info['points']);
        $track->setElevation((float) $info['totalHeight']);
        $track->setDistance((float) $info['totalDistance']);
        $track->setRecordedAt($this->gpsTrack->getRecordedAt());
        $track->setGpxName($this->gpsTrack->getName());

        $this->entityManager->persist($track);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('flash.route_saved', [], null, $locale));

        return $this->redirectToRoute('app_track_info', ['_locale' => $locale, 'trackKey' => $track->getKey()]);
    }

    /**
     * @param list<RoutePoint> $points
     * @return list<array{0: float, 1: float}>
     */
    private static function latLngs(array $points): array
    {
        return array_map(static fn (RoutePoint $p): array => [$p->lat, $p->lon], $points);
    }

    /**
     * The distinct tracks named by `trackKeys[]`, all of which must belong to the current user.
     * Too many keys are rejected before any is looked up, so a huge request cannot cost a query each.
     *
     * @return list<Track>
     *
     * @throws RouteSuggestionException when more keys are given than tracks can be combined
     */
    private function resolveTracks(Request $request): array
    {
        /** @var User $user */
        $user = $this->getUser();

        $keys = array_values(array_unique(array_filter(
            $request->request->all('trackKeys'),
            'is_string'
        )));
        if (count($keys) > $this->routeMaxTracks) {
            throw new RouteSuggestionException('route.error.too_many_tracks', ['%max%' => $this->routeMaxTracks]);
        }

        $tracks = [];
        foreach ($keys as $key) {
            $track = $this->trackRepository->findOneByKey($key);
            if ($track === null || $track->getUser()->getId() !== $user->getId()) {
                throw new NotFoundHttpException('Track not found');
            }
            $tracks[] = $track;
        }

        return $tracks;
    }
}
