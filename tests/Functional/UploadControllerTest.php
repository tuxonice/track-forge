<?php

namespace App\Tests\Functional;

use App\Repository\TrackRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class UploadControllerTest extends WebTestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures/gpx/';

    public function testValidGpxFileIsUploadedAndPersistedWithComputedStats(): void
    {
        $user = $this->persistUser();
        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/track/upload',
            ['trackName' => 'Morning Run', '_token' => $this->csrfToken()],
            ['trackFile' => $this->gpxUpload('valid-track.gpx')]
        );

        self::assertResponseRedirects('/en/tracks/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'New file uploaded');

        $track = $this->trackRepository()->findPageForUser($user, 0, 10)[0] ?? null;
        self::assertNotNull($track);
        self::assertSame('Morning Run', $track->getName());
        // Matches the known fixture stats already verified in GpsTrackTest.
        self::assertSame(2, $track->getTotalPoints());
        self::assertSame(10.0, $track->getElevation());
        self::assertEquals(new \DateTimeImmutable('2026-01-01T10:00:00Z'), $track->getRecordedAt());
        self::assertEquals($track->getRecordedAt(), $track->getDisplayDate());
        self::assertSame('Fixture Track', $track->getGpxName());

        $uploadedFilePath = self::getContainer()->getParameter('app.data_path')
            . sprintf('/profile-%03d/%s', $user->getId(), $track->getFilename());
        self::assertFileExists($uploadedFilePath);
    }

    public function testDisplayDateFallsBackToUploadTimeWhenTheGpxFileHasNoTimeData(): void
    {
        $user = $this->persistUser();
        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/track/upload',
            ['trackName' => 'Untimed Run', '_token' => $this->csrfToken()],
            ['trackFile' => $this->gpxUpload('valid-track-no-time.gpx')]
        );

        self::assertResponseRedirects('/en/tracks/');

        $track = $this->trackRepository()->findPageForUser($user, 0, 10)[0] ?? null;
        self::assertNotNull($track);
        self::assertNull($track->getRecordedAt());
        self::assertEquals($track->getCreatedAt(), $track->getDisplayDate());
    }

    public function testInvalidGpxFileIsRejectedAndNoTrackIsCreated(): void
    {
        $user = $this->persistUser();
        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/track/upload',
            ['trackName' => 'Bad Track', '_token' => $this->csrfToken()],
            ['trackFile' => $this->gpxUpload('no-track-points.gpx')]
        );

        self::assertResponseRedirects('/en/tracks/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'No valid track data found');

        self::assertSame(0, $this->trackRepository()->countForUser($user));
    }

    public function testMissingTrackNameIsRejected(): void
    {
        $user = $this->persistUser();
        $this->client->loginUser($user);

        $this->client->request(
            'POST',
            '/track/upload',
            ['trackName' => '', '_token' => $this->csrfToken()],
            ['trackFile' => $this->gpxUpload('valid-track.gpx')]
        );

        self::assertResponseRedirects('/en/tracks/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Track name is required');

        self::assertSame(0, $this->trackRepository()->countForUser($user));
    }

    public function testMissingFileIsRejected(): void
    {
        $user = $this->persistUser();
        $this->client->loginUser($user);

        $this->client->request('POST', '/track/upload', [
            'trackName' => 'Morning Run',
            '_token' => $this->csrfToken(),
        ]);

        self::assertResponseRedirects('/en/tracks/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'No file was uploaded');
    }

    public function testMissingCsrfTokenIsForbiddenForAnAuthenticatedUser(): void
    {
        // Unlike the unauthenticated login case, an authenticated user hitting the
        // same AccessDeniedException gets a real 403: the security exception listener only
        // falls back to the firewall's entry point when the token isn't fully authenticated.
        $user = $this->persistUser();
        $this->client->loginUser($user);

        $this->client->request('POST', '/track/upload', [
            'trackName' => 'Morning Run',
        ], ['trackFile' => $this->gpxUpload('valid-track.gpx')]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->trackRepository()->countForUser($user));
    }

    /**
     * The controller moves the uploaded file (a real rename(), not a copy) into the data
     * directory, so this always hands it a throwaway copy of the fixture rather than the
     * fixture file itself - otherwise a successful upload would consume it right out of
     * tests/Fixtures/gpx/.
     */
    private function gpxUpload(string $fixture): UploadedFile
    {
        $copyPath = sys_get_temp_dir() . '/' . uniqid('gpx-upload-test-', true) . '.gpx';
        copy(self::FIXTURES . $fixture, $copyPath);

        return new UploadedFile($copyPath, $fixture, 'text/xml', null, true);
    }

    private function csrfToken(): string
    {
        $crawler = $this->client->request('GET', '/en/tracks/');

        return (string) $crawler->filter('form[action="/track/upload"] input[name="_token"]')->attr('value');
    }

    private function trackRepository(): TrackRepository
    {
        return self::getContainer()->get(TrackRepository::class);
    }
}
