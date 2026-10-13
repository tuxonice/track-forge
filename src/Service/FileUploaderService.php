<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FileUploaderService
{
    public function __construct(
        private readonly string $dataPath,
    ) {
    }

    public function getUserDataPath(User $user): string
    {
        return sprintf('%s/profile-%03d', $this->dataPath, (int) $user->getId());
    }

    public function uploadFile(User $user, UploadedFile $file, string $targetFileName): bool
    {
        $userFolder = $this->getUserDataPath($user);

        if (!is_dir($userFolder) && !mkdir($userFolder, 0775, true) && !is_dir($userFolder)) {
            return false;
        }

        try {
            $file->move($userFolder, $targetFileName);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    public function storeContents(User $user, string $contents, string $targetFileName): bool
    {
        $userFolder = $this->getUserDataPath($user);

        if (!is_dir($userFolder) && !mkdir($userFolder, 0775, true) && !is_dir($userFolder)) {
            return false;
        }

        return file_put_contents($userFolder . '/' . $targetFileName, $contents) !== false;
    }

    public function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
