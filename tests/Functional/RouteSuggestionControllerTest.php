<?php

namespace App\Tests\Functional;

class RouteSuggestionControllerTest extends WebTestCase
{
    public function testSuggestingFromTwoCrossingTracksListsRankedRoutes(): void
    {
        $user = $this->persistUser();
        $a = $this->persistTrackFromFixture($user, 'Track A', 'crossing-a.gpx');
        $b = $this->persistTrackFromFixture($user, 'Track B', 'crossing-b.gpx');
        $this->client->loginUser($user);

        $this->client->request('POST', '/en/routes/suggest', [
            '_token' => $this->suggestToken(),
            'trackKeys' => [$a->getKey(), $b->getKey()],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorCount(4, '[data-route-card]');
        self::assertSelectorTextContains('[data-route-card="1"]', 'Track A');
        self::assertSelectorTextContains('[data-route-card="1"]', 'Track B');
        self::assertSelectorExists('[data-route-card="1"] form[action="/routes/save"]');
    }

    public function testPortugueseRouteIsAvailable(): void
    {
        $user = $this->persistUser();
        $a = $this->persistTrackFromFixture($user, 'Track A', 'crossing-a.gpx');
        $b = $this->persistTrackFromFixture($user, 'Track B', 'crossing-b.gpx');
        $this->client->loginUser($user);

        $this->client->request('POST', '/pt/rotas/sugerir', [
            '_token' => $this->suggestToken(),
            'trackKeys' => [$a->getKey(), $b->getKey()],
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testTracksThatNeverCrossShowTheNoRouteMessage(): void
    {
        $user = $this->persistUser();
        $a = $this->persistTrackFromFixture($user, 'Track A', 'crossing-a.gpx');
        $far = $this->persistTrackFromFixture($user, 'Far', 'no-crossing.gpx');
        $this->client->loginUser($user);

        $this->client->request('POST', '/en/routes/suggest', [
            '_token' => $this->suggestToken(),
            'trackKeys' => [$a->getKey(), $far->getKey()],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-route-card]');
        self::assertSelectorTextContains('.alert-info', 'No route');
    }

    public function testAnotherUsersTrackIsNotFound(): void
    {
        $owner = $this->persistUser('owner@example.com');
        $theirs = $this->persistTrackFromFixture($owner, 'Theirs', 'crossing-b.gpx');
        $user = $this->persistUser('me@example.com');
        $mine = $this->persistTrackFromFixture($user, 'Mine', 'crossing-a.gpx');
        $this->client->loginUser($user);

        $this->client->request('POST', '/en/routes/suggest', [
            '_token' => $this->suggestToken(),
            'trackKeys' => [$mine->getKey(), $theirs->getKey()],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testMissingCsrfTokenIsForbidden(): void
    {
        $user = $this->persistUser();
        $a = $this->persistTrackFromFixture($user, 'A', 'crossing-a.gpx');
        $b = $this->persistTrackFromFixture($user, 'B', 'crossing-b.gpx');
        $this->client->loginUser($user);

        $this->client->request('POST', '/en/routes/suggest', ['trackKeys' => [$a->getKey(), $b->getKey()]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testFewerThanTwoTracksRedirectsBackWithAnError(): void
    {
        $user = $this->persistUser();
        $a = $this->persistTrackFromFixture($user, 'A', 'crossing-a.gpx');
        $this->client->loginUser($user);

        $this->client->request('POST', '/en/routes/suggest', [
            '_token' => $this->suggestToken(),
            'trackKeys' => [$a->getKey()],
        ]);

        self::assertResponseRedirects('/en/tracks/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'at least two');
    }

    public function testTheSameTrackKeyTwiceCountsAsOneTrack(): void
    {
        $user = $this->persistUser();
        $a = $this->persistTrackFromFixture($user, 'A', 'crossing-a.gpx');
        $this->client->loginUser($user);

        $this->client->request('POST', '/en/routes/suggest', [
            '_token' => $this->suggestToken(),
            'trackKeys' => [$a->getKey(), $a->getKey()],
        ]);

        self::assertResponseRedirects('/en/tracks/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'at least two');
    }

    public function testMoreKeysThanTheMaximumAreRejectedBeforeAnyTrackIsLookedUp(): void
    {
        $user = $this->persistUser();
        // The track list only shows the suggest form (and its CSRF token) once there are tracks.
        $this->persistTrackFromFixture($user, 'Track A', 'crossing-a.gpx');
        $this->persistTrackFromFixture($user, 'Track B', 'crossing-b.gpx');
        $this->client->loginUser($user);

        // None of these keys exist: a lookup per key would 404, the early check redirects instead.
        $keys = array_map(static fn (int $i): string => 'missing-key-' . $i, range(1, 7));
        $this->client->request('POST', '/en/routes/suggest', [
            '_token' => $this->suggestToken(),
            'trackKeys' => $keys,
        ]);

        self::assertResponseRedirects('/en/tracks/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'at most 6 tracks');
    }

    public function testSavingWithMoreKeysThanTheMaximumIsRejectedBeforeAnyTrackIsLookedUp(): void
    {
        $user = $this->persistUser();
        $a = $this->persistTrackFromFixture($user, 'Track A', 'crossing-a.gpx');
        $b = $this->persistTrackFromFixture($user, 'Track B', 'crossing-b.gpx');
        $this->client->loginUser($user);
        $crawler = $this->client->request('POST', '/en/routes/suggest', [
            '_token' => $this->suggestToken(),
            'trackKeys' => [$a->getKey(), $b->getKey()],
        ]);
        $saveToken = (string) $crawler->filter('[data-route-card="1"] form input[name="_token"]')->attr('value');

        $missing = array_map(static fn (int $i): string => 'missing-key-' . $i, range(1, 5));
        $this->client->request('POST', '/routes/save', [
            '_token' => $saveToken,
            'routeIndex' => 0,
            'trackKeys' => [$a->getKey(), $b->getKey(), ...$missing],
        ]);

        self::assertResponseRedirects('/en/tracks/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'at most 6 tracks');
        self::assertSame(2, $this->entityManager->getRepository(\App\Entity\Track::class)->count(['user' => $user]));
    }

    public function testUnauthenticatedVisitorsAreSentToLogin(): void
    {
        $this->client->request('POST', '/en/routes/suggest', ['trackKeys' => ['x', 'y']]);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testSavingASuggestionCreatesATrackWithAFileAndStats(): void
    {
        $user = $this->persistUser();
        $a = $this->persistTrackFromFixture($user, 'Track A', 'crossing-a.gpx');
        $b = $this->persistTrackFromFixture($user, 'Track B', 'crossing-b.gpx');
        $this->client->loginUser($user);
        $token = $this->suggestToken();

        $crawler = $this->client->request('POST', '/en/routes/suggest', [
            '_token' => $token,
            'trackKeys' => [$a->getKey(), $b->getKey()],
        ]);
        $saveToken = (string) $crawler->filter('[data-route-card="1"] form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/routes/save', [
            '_token' => $saveToken,
            'routeIndex' => 0,
            'trackKeys' => [$a->getKey(), $b->getKey()],
        ]);

        $tracks = $this->entityManager->getRepository(\App\Entity\Track::class)->findBy(['user' => $user], ['id' => 'DESC']);
        self::assertCount(3, $tracks);
        $saved = $tracks[0];
        self::assertStringStartsWith('Suggested route - ', $saved->getName());
        self::assertNotNull($saved->getDistance());
        // The longest suggestion rides A's longer piece (~667 m) and B's longer piece (~556 m).
        self::assertEqualsWithDelta(1.22, $saved->getDistance(), 0.05);
        // getInfo() counts point-to-point steps (n - 1), as for uploaded tracks.
        self::assertGreaterThanOrEqual(3, $saved->getTotalPoints());
        self::assertNull($saved->getRecordedAt());
        $path = self::getContainer()->getParameter('app.data_path')
            . sprintf('/profile-%03d/%s', $user->getId(), $saved->getFilename());
        self::assertFileExists($path);
        self::assertResponseRedirects('/en/track/info/' . $saved->getKey());
    }

    public function testSavingAnOutOfRangeRouteIndexRedirectsWithAnError(): void
    {
        $user = $this->persistUser();
        $a = $this->persistTrackFromFixture($user, 'Track A', 'crossing-a.gpx');
        $b = $this->persistTrackFromFixture($user, 'Track B', 'crossing-b.gpx');
        $this->client->loginUser($user);
        $crawler = $this->client->request('POST', '/en/routes/suggest', [
            '_token' => $this->suggestToken(),
            'trackKeys' => [$a->getKey(), $b->getKey()],
        ]);
        $saveToken = (string) $crawler->filter('[data-route-card="1"] form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/routes/save', [
            '_token' => $saveToken,
            'routeIndex' => 99,
            'trackKeys' => [$a->getKey(), $b->getKey()],
        ]);

        self::assertResponseRedirects('/en/tracks/');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'no longer available');
        self::assertSame(2, $this->entityManager->getRepository(\App\Entity\Track::class)->count(['user' => $user]));
    }

    public function testSavingWithoutACsrfTokenIsForbidden(): void
    {
        $user = $this->persistUser();
        $a = $this->persistTrackFromFixture($user, 'A', 'crossing-a.gpx');
        $b = $this->persistTrackFromFixture($user, 'B', 'crossing-b.gpx');
        $this->client->loginUser($user);

        $this->client->request('POST', '/routes/save', ['routeIndex' => 0, 'trackKeys' => [$a->getKey(), $b->getKey()]]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSavingWithAnotherUsersTrackIsNotFound(): void
    {
        $owner = $this->persistUser('owner@example.com');
        $theirs = $this->persistTrackFromFixture($owner, 'Theirs', 'crossing-b.gpx');
        $user = $this->persistUser('me@example.com');
        $mine = $this->persistTrackFromFixture($user, 'Mine', 'crossing-a.gpx');
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/en/tracks/');
        // Any valid token for the save action: reuse one rendered by a legitimate suggestion page.
        $own = $this->persistTrackFromFixture($user, 'Own B', 'crossing-b.gpx');
        $page = $this->client->request('POST', '/en/routes/suggest', [
            '_token' => (string) $crawler->filter('form[action="/en/routes/suggest"] input[name="_token"]')->attr('value'),
            'trackKeys' => [$mine->getKey(), $own->getKey()],
        ]);
        $saveToken = (string) $page->filter('[data-route-card="1"] form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/routes/save', [
            '_token' => $saveToken,
            'routeIndex' => 0,
            'trackKeys' => [$mine->getKey(), $theirs->getKey()],
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheResultsPageHasNoLanguageSwitcherLinkToThePostOnlyRoute(): void
    {
        $user = $this->persistUser();
        $a = $this->persistTrackFromFixture($user, 'Track A', 'crossing-a.gpx');
        $b = $this->persistTrackFromFixture($user, 'Track B', 'crossing-b.gpx');
        $this->client->loginUser($user);

        $crawler = $this->client->request('POST', '/en/routes/suggest', [
            '_token' => $this->suggestToken(),
            'trackKeys' => [$a->getKey(), $b->getKey()],
        ]);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href="/pt/rotas/sugerir"], a[href="/en/routes/suggest"]'));
        self::assertCount(0, $crawler->filter('.dropdown-menu a.dropdown-item'));

        $tracksPage = $this->client->request('GET', '/en/tracks/');
        self::assertSelectorExists('a.dropdown-item[href="/pt/percursos/"]');
        self::assertGreaterThan(0, $tracksPage->filter('.dropdown-menu a.dropdown-item')->count());
    }

    private function suggestToken(): string
    {
        $crawler = $this->client->request('GET', '/en/tracks/');

        return (string) $crawler->filter('form[action="/en/routes/suggest"] input[name="_token"]')->attr('value');
    }
}
