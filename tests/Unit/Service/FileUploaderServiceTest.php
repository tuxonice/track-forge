<?php

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Service\FileUploaderService;
use PHPUnit\Framework\TestCase;

class FileUploaderServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/tf-uploader-' . uniqid();
    }

    protected function tearDown(): void
    {
        (new FileUploaderService($this->dir))->deleteDirectory($this->dir);
    }

    public function testStoreContentsWritesTheFileIntoTheUsersFolder(): void
    {
        $service = new FileUploaderService($this->dir);
        $user = new User('a@example.com');

        self::assertTrue($service->storeContents($user, '<gpx/>', 'x.gpx'));

        self::assertSame('<gpx/>', file_get_contents($service->getUserDataPath($user) . '/x.gpx'));
    }
}
