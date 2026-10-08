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

namespace CarpetAvenue\FeedExport\Controller\Adminhtml\Profile;

use CarpetAvenue\FeedExport\Model\Test\TestFileStorage;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;

/**
 * Serves the last "Test XSL Template" output as a download (several files are zipped).
 */
class DownloadTestExport extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_FeedExport::profile';

    public function __construct(
        Context $context,
        private readonly TestFileStorage $testFileStorage
    ) {
        parent::__construct($context);
    }

    public function execute(): Raw
    {
        /** @var Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $files = $this->testFileStorage->load(
            (int)$this->getRequest()->getParam('profile_id'),
            (int)$this->_auth->getUser()?->getId()
        );
        if (!$files) {
            return $result->setHeader('Content-Type', 'text/plain; charset=UTF-8', true)
                ->setContents(__('No test export found. Run "Test XSL Template" first.')->render());
        }

        if (count($files) === 1) {
            $filename = basename((string)array_key_first($files));
            $content = (string)reset($files);
        } else {
            [$filename, $content] = $this->zip($files);
        }
        return $result->setHttpResponseCode(200)
            ->setHeader('Content-Type', 'application/octet-stream', true)
            ->setHeader('Content-Length', (string)strlen($content), true)
            ->setHeader('Cache-Control', 'must-revalidate, post-check=0, pre-check=0', true)
            ->setHeader('Content-Disposition', 'attachment; filename="' . addcslashes($filename, '"\\') . '"', true)
            ->setContents($content);
    }

    /**
     * @param array<string, string> $files
     * @return array{0: string, 1: string}
     */
    private function zip(array $files): array
    {
        $path = tempnam(sys_get_temp_dir(), 'feedexport');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($files as $filename => $content) {
            $zip->addFromString(basename((string)$filename), $content);
        }
        $zip->close();
        $content = (string)file_get_contents($path);
        unlink($path);
        return ['test_export.zip', $content];
    }
}
