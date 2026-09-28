<?php

namespace App\Tests\Functional;

use App\Entity\Track;
use App\Entity\User;

class HomeControllerTest extends WebTestCase
{
    public function testRootRedirectsToProfile(): void
    {
        $user = $this->persistUser();
        $this->client->loginUser($user);

        $this->client->request('GET', '/');

        self::assertResponseRedirects('/en/tracks/');
    }

    public function testProfileListsTheUsersOwnTracksNewestFirst(): void
    {
        $user = $this->persistUser();
        $this->persistTrack($user, 'oldest', new \DateTimeImmutable('2026-01-01 08:00:00'));
        $this->persistTrack($user, 'newest', new \DateTimeImmutable('2026-01-02 08:00:00'));

        $other = $this->persistUser('other@example.com');
        $this->persistTrack($other, 'not-mine', new \DateTimeImmutable('2026-01-03 08:00:00'));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/en/tracks/');

        self::assertResponseIsSuccessful();
        $names = $crawler->filter('table tbody tr td:first-child')->each(
            static fn ($node) => trim($node->text())
        );
        self::assertSame(['newest', 'oldest'], array_slice($names, 0, 2));
        self::assertStringNotContainsString('not-mine', $crawler->filter('body')->text());
    }

    public function testProfilePaginatesAcrossPages(): void
    {
        $user = $this->persistUser();
        for ($i = 1; $i <= 11; ++$i) {
            $this->persistTrack($user, "track-$i", new \DateTimeImmutable(sprintf('2026-01-%02d 08:00:00', $i)));
        }
        $this->client->loginUser($user);

        // PAGINATION_IPP defaults to 10, so the 11th (oldest) track spills onto page 2.
        $this->client->request('GET', '/en/tracks/');
        self::assertSelectorTextContains('body', 'track-11');

        $crawler = $this->client->request('GET', '/en/tracks/2');
        self::assertSelectorTextContains('body', 'track-1');
        self::assertStringNotContainsString('track-11', $crawler->filter('.card-body')->text());
    }

    private function persistTrack(User $user, string $name, \DateTimeImmutable $createdAt): Track
    {
        $track = new Track($user, $name, uniqid('track-', true) . '.gpx');

        $property = new \ReflectionProperty(Track::class, 'createdAt');
        $property->setValue($track, $createdAt);

        $this->entityManager->persist($track);
        $this->entityManager->flush();

        return $track;
    }
}
