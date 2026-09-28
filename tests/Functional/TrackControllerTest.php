<?php

namespace App\Tests\Functional;

use App\Entity\Track;
use App\Entity\User;

class TrackControllerTest extends WebTestCase
{
    public function testTrackInfoRendersForItsOwner(): void
    {
        $user = $this->persistUser();
        $track = $this->persistTrack($user, 'Morning Run');
        $this->client->loginUser($user);

        $this->client->request('GET', '/en/track/info/' . $track->getKey());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Morning Run');
    }

    public function testTrackInfoRedirectsWhenTheTrackBelongsToAnotherUser(): void
    {
        $owner = $this->persistUser('owner@example.com');
        $track = $this->persistTrack($owner, 'Owners Track');

        $visitor = $this->persistUser('visitor@example.com');
        $this->client->loginUser($visitor);

        $this->client->request('GET', '/en/track/info/' . $track->getKey());

        self::assertResponseRedirects('/en/tracks/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Track does not exist');
    }

    public function testTrackInfoRedirectsWhenTheKeyDoesNotExist(): void
    {
        $user = $this->persistUser();
        $this->client->loginUser($user);

        $this->client->request('GET', '/en/track/info/does-not-exist');

        self::assertResponseRedirects('/en/tracks/');
    }

    public function testDeletingOwnTrackRemovesItAndItsFile(): void
    {
        $user = $this->persistUser();
        $track = $this->persistTrack($user, 'Morning Run');
        $filePath = $this->writeTrackFile($user, $track);
        $this->client->loginUser($user);

        $token = $this->deleteTokenFrom($track);

        $this->client->request('POST', '/track/delete', [
            'track_key' => $track->getKey(),
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/en/tracks/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Track deleted');

        self::assertFileDoesNotExist($filePath);
        self::assertNull($this->entityManager->getRepository(Track::class)->findOneByKey($track->getKey()));
    }

    public function testDeletingAnotherUsersTrackIsRejected(): void
    {
        $owner = $this->persistUser('owner@example.com');
        $ownerTrack = $this->persistTrack($owner, 'Owners Track');
        $filePath = $this->writeTrackFile($owner, $ownerTrack);

        $visitor = $this->persistUser('visitor@example.com');
        $visitorTrack = $this->persistTrack($visitor, 'Visitors Own Track');
        $this->client->loginUser($visitor);

        // The visitor has a valid session and a legitimate CSRF token of their own (the
        // token itself is session+intention bound, not track-specific) - they're just
        // pointing track_key at someone else's track.
        $token = $this->deleteTokenFrom($visitorTrack);

        $this->client->request('POST', '/track/delete', [
            'track_key' => $ownerTrack->getKey(),
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/en/tracks/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Track does not exist');

        self::assertFileExists($filePath);
        self::assertNotNull($this->entityManager->getRepository(Track::class)->findOneByKey($ownerTrack->getKey()));
    }

    public function testDeletingWithoutCsrfTokenIsForbiddenForAnAuthenticatedUser(): void
    {
        $user = $this->persistUser();
        $track = $this->persistTrack($user, 'Morning Run');
        $this->client->loginUser($user);

        $this->client->request('POST', '/track/delete', ['track_key' => $track->getKey()]);

        self::assertResponseStatusCodeSame(403);
    }

    private function persistTrack(User $user, string $name): Track
    {
        $track = new Track($user, $name, uniqid('track-', true) . '.gpx');
        $this->entityManager->persist($track);
        $this->entityManager->flush();

        return $track;
    }

    private function writeTrackFile(User $user, Track $track): string
    {
        $dataPath = self::getContainer()->getParameter('app.data_path');
        \assert(is_string($dataPath));

        $userFolder = sprintf('%s/profile-%03d', $dataPath, (int) $user->getId());
        if (!is_dir($userFolder)) {
            mkdir($userFolder, 0775, true);
        }

        $filePath = $userFolder . '/' . $track->getFilename();
        file_put_contents($filePath, 'dummy content');

        return $filePath;
    }

    /**
     * Fetches a valid "track-delete" CSRF token the same way a browser would: by rendering
     * a page for the currently logged-in client that contains the form. Fetching it via the
     * CsrfTokenManager service directly only works while a request is actively being
     * handled - RequestStack has no session once $client->request() has already returned.
     */
    private function deleteTokenFrom(Track $viewableTrack): string
    {
        $crawler = $this->client->request('GET', '/en/track/info/' . $viewableTrack->getKey());

        return (string) $crawler->filter('form[action="/track/delete"] input[name="_token"]')->first()->attr('value');
    }
}
