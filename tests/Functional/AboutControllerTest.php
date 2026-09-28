<?php

namespace App\Tests\Functional;

class AboutControllerTest extends WebTestCase
{
    public function testThePageRendersWithTheExpectedContentInEnglish(): void
    {
        $this->client->request('GET', '/en/about');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'About TrackMix');
        self::assertSelectorTextContains('body', 'Upload GPX files');
    }

    public function testThePageRendersWithTheExpectedContentInPortuguese(): void
    {
        $this->client->request('GET', '/pt/sobre');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Sobre o TrackMix');
        self::assertSelectorTextContains('body', 'Enviar ficheiros GPX');
    }

    public function testTheNavLinkPointsToTheAboutPage(): void
    {
        $user = $this->persistUser();
        $this->client->loginUser($user);

        $this->client->request('GET', '/en/tracks/');

        self::assertSelectorExists('a[href="/en/about"]');
    }
}
