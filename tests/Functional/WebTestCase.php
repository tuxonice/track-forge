<?php

namespace App\Tests\Functional;

use App\Entity\Track;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as BaseWebTestCase;

abstract class WebTestCase extends BaseWebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        // Rebuild the schema fresh from entity metadata, same approach as the repository
        // integration tests: isolated and fast, independent of migration history.
        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        // cache.app (and cache.rate_limiter, which just extends it) is filesystem-backed and
        // persists across kernel reboots, i.e. across test methods, unless cleared. The
        // login-code rate limiters rely on it - without clearing it, rate-limit state can
        // bleed between tests that hit the same limiter key.
        self::getContainer()->get('cache.app')->clear();
        self::getContainer()->get('cache.rate_limiter')->clear();

        $this->removeUploadedTestFiles();
    }

    protected function tearDown(): void
    {
        $this->removeUploadedTestFiles();

        parent::tearDown();
    }

    protected function persistUser(string $email = 'user@example.com'): User
    {
        $user = new User($email);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    protected function persistTrackFromFixture(User $user, string $name, string $fixture): Track
    {
        $track = new Track($user, $name, uniqid('track-', true) . '.gpx');
        $this->entityManager->persist($track);
        $this->entityManager->flush();

        $dataPath = self::getContainer()->getParameter('app.data_path');
        \assert(is_string($dataPath));
        $userFolder = sprintf('%s/profile-%03d', $dataPath, (int) $user->getId());
        if (!is_dir($userFolder)) {
            mkdir($userFolder, 0775, true);
        }
        copy(__DIR__ . '/../Fixtures/gpx/' . $fixture, $userFolder . '/' . $track->getFilename());

        return $track;
    }

    private function removeUploadedTestFiles(): void
    {
        $dataPath = self::getContainer()->getParameter('app.data_path');
        \assert(is_string($dataPath));

        // Belt-and-suspenders: this recursively deletes everything under $dataPath, so
        // refuse to run against anything that isn't clearly the isolated test directory -
        // a misconfigured app.data_path (UPLOAD_DATA_SUBDIR) must never make this touch
        // real uploaded data again.
        if (!str_ends_with(rtrim($dataPath, '/'), '/var/data_test')) {
            throw new \LogicException(\sprintf(
                'Refusing to clean up "%s": it does not look like the isolated test upload directory.',
                $dataPath
            ));
        }

        if (!is_dir($dataPath)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dataPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dataPath);
    }
}
