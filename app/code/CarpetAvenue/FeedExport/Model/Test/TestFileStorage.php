<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_FeedExport
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\FeedExport\Model\Test;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;

/**
 * Keeps the last "Test XSL Template" output per profile and admin user in var/tmp,
 * so "Serve file to browser" can download it with a follow-up GET.
 */
class TestFileStorage
{
    private const DIRECTORY = 'carpetavenue_feedexport';

    private WriteInterface $tmpDirectory;

    public function __construct(Filesystem $filesystem)
    {
        $this->tmpDirectory = $filesystem->getDirectoryWrite(DirectoryList::TMP);
    }

    /**
     * @param array<string, string> $files filename => content
     * @throws FileSystemException
     */
    public function save(int $profileId, int $userId, array $files): void
    {
        $this->tmpDirectory->writeFile(
            $this->getPath($profileId, $userId),
            (string)json_encode($files, JSON_INVALID_UTF8_SUBSTITUTE)
        );
    }

    /**
     * @return array<string, string> filename => content, empty when nothing is stored
     */
    public function load(int $profileId, int $userId): array
    {
        $path = $this->getPath($profileId, $userId);
        try {
            if (!$this->tmpDirectory->isFile($path)) {
                return [];
            }
            $files = json_decode($this->tmpDirectory->readFile($path), true);
        } catch (FileSystemException $e) {
            return [];
        }
        return is_array($files) ? array_filter($files, 'is_string') : [];
    }

    private function getPath(int $profileId, int $userId): string
    {
        return sprintf('%s/test_%d_%d.json', self::DIRECTORY, $profileId, $userId);
    }
}
