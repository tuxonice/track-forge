<?php

namespace App\Controller;

use App\Entity\Track;
use App\Entity\User;
use App\Exception\GpxValidationException;
use App\Gps\GpsTrack;
use App\Service\FileUploaderService;
use App\Service\GpxValidator;
use App\Validator\XmlValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class UploadController extends AbstractController
{
    public function __construct(
        private readonly XmlValidator $xmlValidator,
        private readonly GpxValidator $gpxValidator,
        private readonly FileUploaderService $fileUploaderService,
        private readonly GpsTrack $gpsTrack,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly string $gpxSchemaPath,
        private readonly string $gpxSchema10Path,
    ) {
    }

    #[Route('/track/upload', name: 'app_track_upload', methods: ['POST'])]
    public function uploadTrack(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('track-upload', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }

        /** @var User $user */
        $user = $this->getUser();
        $locale = $user->getLocale();

        /** @var UploadedFile|null $file */
        $file = $request->files->get('trackFile');
        if (!$file) {
            $this->addFlash('danger', $this->translator->trans('flash.no_file_uploaded', [], null, $locale));

            return $this->redirectToRoute('app_tracks', ['_locale' => $locale]);
        }

        $trackName = trim(htmlspecialchars((string) $request->request->get('trackName', '')));
        if ($trackName === '') {
            $this->addFlash('danger', $this->translator->trans('flash.track_name_required', [], null, $locale));

            return $this->redirectToRoute('app_tracks', ['_locale' => $locale]);
        }

        try {
            $this->assertValidGpxFile($file);
        } catch (GpxValidationException $e) {
            $this->addFlash('danger', $this->translator->trans($e->getMessage(), $e->getParameters(), null, $locale));

            return $this->redirectToRoute('app_tracks', ['_locale' => $locale]);
        }

        $targetFileName = uniqid() . '.gpx';

        if (!$this->fileUploaderService->uploadFile($user, $file, $targetFileName)) {
            $this->addFlash('danger', $this->translator->trans('flash.upload_failed', [], null, $locale));

            return $this->redirectToRoute('app_tracks', ['_locale' => $locale]);
        }

        $trackFilePath = $this->fileUploaderService->getUserDataPath($user) . '/' . $targetFileName;
        $this->gpsTrack->process($trackFilePath);
        $trackInfo = $this->gpsTrack->getInfo();

        $track = new Track($user, $trackName, $targetFileName);
        $track->setTotalPoints($trackInfo['points']);
        $track->setElevation((float) $trackInfo['totalHeight']);
        $track->setDistance((float) $trackInfo['totalDistance']);
        $track->setRecordedAt($this->gpsTrack->getRecordedAt());
        $track->setGpxName($this->gpsTrack->getName());

        $this->entityManager->persist($track);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('flash.upload_success', [], null, $locale));

        return $this->redirectToRoute('app_tracks', ['_locale' => $locale]);
    }

    private function assertValidGpxFile(UploadedFile $file): void
    {
        try {
            $namespace = $this->gpxValidator->detectNamespace($file);
            $schemaPath = match ($namespace) {
                'http://www.topografix.com/GPX/1/0' => $this->gpxSchema10Path,
                'http://www.topografix.com/GPX/1/1' => $this->gpxSchemaPath,
                default => null,
            };

            if ($schemaPath === null) {
                throw new GpxValidationException('gpx_validation.invalid_namespace');
            }

            $isValidXml = $this->xmlValidator->isValid(
                (string) file_get_contents($file->getPathname()),
                (string) file_get_contents($schemaPath)
            );

            if (!$isValidXml) {
                throw new GpxValidationException('gpx_validation.invalid_gpx_format');
            }

            $this->gpxValidator->validate($file);
        } catch (GpxValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new GpxValidationException('gpx_validation.validation_error', ['%details%' => $e->getMessage()]);
        }
    }
}
