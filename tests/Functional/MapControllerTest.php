<?php

namespace App\Tests\Functional;

use App\Entity\Track;
use App\Entity\User;

class MapControllerTest extends WebTestCase
{
    private const FIXTURES = __DIR__ . '/../Fixtures/gpx/';

    public function testMapRendersForTheOwnerWhenTheFileExists(): void
    {
        $user = $this->persistUser();
        $track = $this->persistTrack($user, 'Morning Run');
        $this->writeTrackFile($user, $track, file_get_contents(self::FIXTURES . 'valid-track.gpx'));
        $this->client->loginUser($user);

        $this->client->request('GET', '/en/map/' . $track->getKey());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Morning Run');
        // Matches the known fixture stats already verified in GpsTrackTest.
        self::assertSelectorTextContains('#samplecontent', 'Points: 2');
    }

    public function testMapRedirectsToHomeWhenTheTrackKeyIsUnknown(): void
    {
        $user = $this->persistUser();
        $this->client->loginUser($user);

        $this->client->request('GET', '/en/map/does-not-exist');

        self::assertResponseRedirects('/');
    }

    public function testMapRedirectsWhenTheTrackBelongsToAnotherUser(): void
    {
        $owner = $this->persistUser('owner@example.com');
        $track = $this->persistTrack($owner, 'Owners Track');
        $this->writeTrackFile($owner, $track, 'dummy content');

        $visitor = $this->persistUser('visitor@example.com');
        $this->client->loginUser($visitor);

        $this->client->request('GET', '/en/map/' . $track->getKey());

        self::assertResponseRedirects('/en/tracks/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Track does not exist');
    }

    public function testMapRedirectsWhenTheFileIsMissingOnDisk(): void
    {
        $user = $this->persistUser();
        $track = $this->persistTrack($user, 'Morning Run');
        $this->client->loginUser($user);

        $this->client->request('GET', '/en/map/' . $track->getKey());

        self::assertResponseRedirects('/en/tracks/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Track file does not exist');
    }

    private function persistTrack(User $user, string $name): Track
    {
        $track = new Track($user, $name, uniqid('track-', true) . '.gpx');
        $this->entityManager->persist($track);
        $this->entityManager->flush();

        return $track;
    }

    private function writeTrackFile(User $user, Track $track, string|false $content): void
    {
        $dataPath = self::getContainer()->getParameter('app.data_path');
        \assert(is_string($dataPath));

        $userFolder = sprintf('%s/profile-%03d', $dataPath, (int) $user->getId());
        if (!is_dir($userFolder)) {
            mkdir($userFolder, 0775, true);
        }

        file_put_contents($userFolder . '/' . $track->getFilename(), $content);
    }
}
