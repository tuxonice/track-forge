<?php

namespace App\Tests\Functional;

use App\Entity\Track;
use App\Entity\User;

class AccountControllerTest extends WebTestCase
{
    public function testAccountPageRendersForALoggedInUser(): void
    {
        $user = $this->persistUser('me@example.com');
        $this->persistTrack($user);
        $this->client->loginUser($user);

        $this->client->request('GET', '/en/account');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'me@example.com');
        self::assertSelectorExists('form[action="/account/delete"]');
    }

    public function testAccountPageIsLocalized(): void
    {
        $this->client->loginUser($this->persistUser());

        $this->client->request('GET', '/pt/conta');

        self::assertResponseIsSuccessful();
    }

    public function testAccountPageRedirectsAnonymousVisitorsToLogin(): void
    {
        $this->client->request('GET', '/en/account');

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testDeletingTheAccountRemovesUserTracksAndFilesAndLogsOut(): void
    {
        $user = $this->persistUser('me@example.com');
        $track = $this->persistTrack($user);
        $filePath = $this->writeTrackFile($user, $track);
        $this->client->loginUser($user);
        $userId = $user->getId();

        $token = $this->deleteTokenFrom();
        $this->client->request('POST', '/account/delete', ['_token' => $token, 'email' => 'me@example.com']);

        self::assertResponseRedirects('/en/login');
        self::assertFileDoesNotExist($filePath);
        self::assertDirectoryDoesNotExist(\dirname($filePath));

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(User::class, $userId));
        self::assertCount(0, $this->entityManager->getRepository(Track::class)->findAll());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Your account has been deleted');

        $this->client->request('GET', '/en/account');
        self::assertResponseRedirects();
    }

    public function testDeletingWithAWrongEmailChangesNothing(): void
    {
        $user = $this->persistUser('me@example.com');
        $track = $this->persistTrack($user);
        $filePath = $this->writeTrackFile($user, $track);
        $this->client->loginUser($user);

        $token = $this->deleteTokenFrom();
        $this->client->request('POST', '/account/delete', ['_token' => $token, 'email' => 'other@example.com']);

        self::assertResponseRedirects('/en/account');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'does not match');

        self::assertFileExists($filePath);
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->getRepository(User::class)->findOneBy(['email' => 'me@example.com']));
        self::assertCount(1, $this->entityManager->getRepository(Track::class)->findAll());
    }

    public function testDeletingWithoutCsrfTokenIsForbidden(): void
    {
        $user = $this->persistUser('me@example.com');
        $this->client->loginUser($user);

        $this->client->request('POST', '/account/delete', ['email' => 'me@example.com']);

        self::assertResponseStatusCodeSame(403);
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->getRepository(User::class)->findOneBy(['email' => 'me@example.com']));
    }

    public function testDeletingAnAccountLeavesOtherUsersUntouched(): void
    {
        $other = $this->persistUser('other@example.com');
        $otherTrack = $this->persistTrack($other);
        $otherFile = $this->writeTrackFile($other, $otherTrack);

        $user = $this->persistUser('me@example.com');
        $this->client->loginUser($user);

        $token = $this->deleteTokenFrom();
        $this->client->request('POST', '/account/delete', ['_token' => $token, 'email' => 'me@example.com']);

        self::assertFileExists($otherFile);
        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->getRepository(User::class)->findOneBy(['email' => 'other@example.com']));
        self::assertCount(1, $this->entityManager->getRepository(Track::class)->findAll());
    }

    private function persistTrack(User $user): Track
    {
        $track = new Track($user, 'Morning Run', uniqid('track-', true) . '.gpx');
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

    private function deleteTokenFrom(): string
    {
        $crawler = $this->client->request('GET', '/en/account');

        return (string) $crawler->filter('form[action="/account/delete"] input[name="_token"]')->first()->attr('value');
    }
}
