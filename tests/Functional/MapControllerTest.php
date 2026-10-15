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
        self::assertStringContainsString('\/en\/tracks\/', (string) $this->client->getResponse()->getContent());
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

    public function testMultiMapRendersEachSelectedTrackWithItsOwnColor(): void
    {
        $user = $this->persistUser();
        $first = $this->persistTrack($user, 'First');
        $second = $this->persistTrack($user, 'Second');
        foreach ([$first, $second] as $track) {
            $this->writeTrackFile($user, $track, file_get_contents(self::FIXTURES . 'valid-track.gpx'));
        }
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/en/map?tracks=' . $first->getKey() . ',' . $second->getKey());

        self::assertResponseIsSuccessful();
        $html = $crawler->html();
        self::assertStringContainsString('#ff7a00', $html);
        self::assertStringContainsString('#1f78ff', $html);
        self::assertStringNotContainsString('#1a9850', $html);
        self::assertStringContainsString('First', $html);
        self::assertStringContainsString('Second', $html);
        self::assertStringContainsString('\/en\/tracks\/', $html);
    }

    public function testMultiMapRejectsMoreThanThreeTracks(): void
    {
        $user = $this->persistUser();
        $this->client->loginUser($user);

        $this->client->request('GET', '/en/map?tracks=a,b,c,d');

        self::assertResponseRedirects('/en/tracks/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'at most 3 tracks');
    }

    public function testMultiMapRedirectsWhenNoTrackIsSelected(): void
    {
        $user = $this->persistUser();
        $this->client->loginUser($user);

        $this->client->request('GET', '/en/map');

        self::assertResponseRedirects('/en/tracks/');
    }

    public function testMultiMapRejectsATrackOfAnotherUser(): void
    {
        $owner = $this->persistUser('owner@example.com');
        $mine = $this->persistTrack($owner, 'Mine');
        $this->writeTrackFile($owner, $mine, file_get_contents(self::FIXTURES . 'valid-track.gpx'));
        $visitor = $this->persistUser('visitor@example.com');
        $theirs = $this->persistTrack($visitor, 'Theirs');
        $this->writeTrackFile($visitor, $theirs, file_get_contents(self::FIXTURES . 'valid-track.gpx'));
        $this->client->loginUser($visitor);

        $this->client->request('GET', '/en/map?tracks=' . $theirs->getKey() . ',' . $mine->getKey());

        self::assertResponseRedirects('/en/tracks/');
    }

    public function testMultiMapIsAvailableOnThePortugueseRoute(): void
    {
        $user = $this->persistUser();
        $track = $this->persistTrack($user, 'Morning Run');
        $this->writeTrackFile($user, $track, file_get_contents(self::FIXTURES . 'valid-track.gpx'));
        $this->client->loginUser($user);

        $this->client->request('GET', '/pt/mapa?tracks=' . $track->getKey());

        self::assertResponseIsSuccessful();
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
