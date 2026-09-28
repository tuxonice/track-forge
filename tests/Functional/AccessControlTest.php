<?php

namespace App\Tests\Functional;

class AccessControlTest extends WebTestCase
{
    public function testAProtectedRouteRedirectsAnUnauthenticatedVisitorToTheLoginForm(): void
    {
        $this->client->request('GET', '/en/tracks/');

        self::assertResponseRedirects('/en/login');
    }

    public function testTheLoginRouteIsReachableWithoutAuthentication(): void
    {
        $this->client->request('GET', '/en/login');

        self::assertResponseIsSuccessful();
    }

    public function testTheAboutRouteIsReachableWithoutAuthentication(): void
    {
        $this->client->request('GET', '/en/about');

        self::assertResponseIsSuccessful();
    }

    public function testSecurityHeadersAreSetOnEveryResponse(): void
    {
        $this->client->request('GET', '/en/login');

        $response = $this->client->getResponse();
        self::assertTrue($response->headers->has('Content-Security-Policy'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
    }
}
