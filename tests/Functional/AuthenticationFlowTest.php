<?php

namespace App\Tests\Functional;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

class AuthenticationFlowTest extends WebTestCase
{
    public function testEnteringTheCorrectCodeAuthenticatesAndRedirectsToTheProfile(): void
    {
        $code = $this->requestCodeFor('code-fresh@example.com');

        $this->client->request('POST', '/en/login/verify', [
            'code' => $code,
            '_token' => $this->verifyCsrfToken(),
        ]);

        self::assertResponseRedirects('/en/tracks/');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testAWrongCodeFailsAndRedisplaysTheVerifyFormWithADangerFlash(): void
    {
        $code = $this->requestCodeFor('code-wrong@example.com');

        $this->client->request('POST', '/en/login/verify', [
            'code' => $this->wrongCodeFor($code),
            '_token' => $this->verifyCsrfToken(),
        ]);

        self::assertResponseRedirects('/en/login/verify');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Incorrect code');
    }

    public function testFiveWrongAttemptsInvalidatesTheCodeAndRequiresANewRequest(): void
    {
        $code = $this->requestCodeFor('code-exhausted@example.com');
        $wrongCode = $this->wrongCodeFor($code);

        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('POST', '/en/login/verify', [
                'code' => $wrongCode,
                '_token' => $this->verifyCsrfToken(),
            ]);
            self::assertResponseRedirects('/en/login/verify');
        }

        // The 5th wrong attempt above already exhausted the cap - this submission (even
        // with the real code) must now be treated as "no valid code left".
        $this->client->request('POST', '/en/login/verify', [
            'code' => $code,
            '_token' => $this->verifyCsrfToken(),
        ]);

        self::assertResponseRedirects('/en/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Too many incorrect attempts');
    }

    public function testTheVerifyRateLimiterSurvivesARequestForANewCode(): void
    {
        $email = 'code-grinding@example.com';
        $firstCode = $this->requestCodeFor($email);
        $wrongCode = $this->wrongCodeFor($firstCode);

        // Exhausts the by-email verify rate limiter (capacity 5 by default, same as
        // MAX_ATTEMPTS) without tripping the per-code attempt cap - proves the limiter is
        // an independent bound, not just a restatement of the attempt cap.
        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('POST', '/en/login/verify', [
                'code' => $wrongCode,
                '_token' => $this->verifyCsrfToken(),
            ]);
        }

        // A fresh code request resets the per-code attempt counter to 0, but must NOT
        // reset the by-email verify rate limiter - this is exactly the gap being closed:
        // without a separate limiter, an attacker could grind indefinitely by just
        // requesting new codes.
        $secondCode = $this->requestCodeFor($email);

        $this->client->request('POST', '/en/login/verify', [
            'code' => $secondCode,
            '_token' => $this->verifyCsrfToken(),
        ]);

        self::assertResponseStatusCodeSame(429);
    }

    public function testAnExpiredCodeIsRejected(): void
    {
        $code = $this->requestCodeFor('code-expired@example.com');

        // $this->entityManager (captured in setUp(), before any request) is stale by this
        // point: each $client->request() above rebooted the kernel, which rebuilds the DI
        // container - and with it, a brand-new EntityManager instance. Fetching $user AND
        // flushing its mutation both need to go through this current container's EM, or the
        // change here is invisible to that EM's UnitOfWork and never reaches the database.
        $currentEntityManager = self::getContainer()->get(EntityManagerInterface::class);
        $userRepository = self::getContainer()->get(UserRepository::class);
        $user = $userRepository->findOneByEmail('code-expired@example.com');
        self::assertNotNull($user);
        $user->setLoginCodeExpiresAt(new \DateTimeImmutable('-1 second'));
        $currentEntityManager->flush();

        $this->client->request('POST', '/en/login/verify', [
            'code' => $code,
            '_token' => $this->verifyCsrfToken(),
        ]);

        self::assertResponseRedirects('/en/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'expired');
    }

    public function testLogoutEndsTheSessionAndProtectedRoutesRedirectAgain(): void
    {
        $user = $this->persistUser('logout@example.com');
        $this->client->loginUser($user);

        $this->client->request('GET', '/en/tracks/');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/logout');
        self::assertResponseRedirects();

        $this->client->request('GET', '/en/tracks/');
        self::assertResponseRedirects('/en/login');
    }

    /**
     * Requests a real login code the same way a browser would (submits the email form),
     * then reads the 6-digit code out of the captured test email - there's no link to
     * extract any more, so this mirrors exactly how a real user reads their code.
     */
    private function requestCodeFor(string $email): string
    {
        $crawler = $this->client->request('GET', '/en/login');
        $token = (string) $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/en/login', [
            'email' => $email,
            '_token' => $token,
        ]);

        $mailerMessage = self::getMailerMessage(0);
        self::assertNotNull($mailerMessage);

        $body = $mailerMessage->getTextBody();
        self::assertIsString($body);
        self::assertMatchesRegularExpression('/\b\d{6}\b/', $body);
        preg_match('/\b(\d{6})\b/', $body, $matches);

        return $matches[1];
    }

    /**
     * Guaranteed different from $correctCode (never relies on a hardcoded guess like
     * '000000', which has a real if tiny chance of colliding with the actual random code
     * and making the test flaky).
     */
    private function wrongCodeFor(string $correctCode): string
    {
        $wrongCodeAsInt = ((int) $correctCode + 1) % 1000000;

        return str_pad((string) $wrongCodeAsInt, 6, '0', STR_PAD_LEFT);
    }

    private function verifyCsrfToken(): string
    {
        $crawler = $this->client->request('GET', '/en/login/verify');

        return (string) $crawler->filter('input[name="_token"]')->attr('value');
    }
}
