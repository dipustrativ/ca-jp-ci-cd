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

use CarpetAvenue\FeedExport\Api\FeedExporterInterface;
use CarpetAvenue\FeedExport\Api\ProfileRepositoryInterface;
use CarpetAvenue\FeedExport\Model\Test\TestFileStorage;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * "Test XSL Template": runs the editor's (unsaved) template against one product of the saved profile.
 */
class ValidateXslTemplate extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_FeedExport::profile';

    public function __construct(
        Context $context,
        private readonly ProfileRepositoryInterface $profileRepository,
        private readonly FeedExporterInterface $feedExporter,
        private readonly TestFileStorage $testFileStorage
    ) {
        parent::__construct($context);
    }

    public function execute(): Raw
    {
        /** @var Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHeader('Content-Type', 'text/plain; charset=UTF-8', true);

        $template = (string)$this->getRequest()->getPost('xsl_template', '');
        $profileId = (int)$this->getRequest()->getPost('profile_id');
        $productId = (int)$this->getRequest()->getPost('test_id');
        try {
            if (trim($template) === '') {
                throw new LocalizedException(__('No XSL Template supplied.'));
            }
            if (!$profileId) {
                throw new LocalizedException(__('Save the profile first, then test the XSL Template.'));
            }
            if (!$productId) {
                throw new LocalizedException(__('Enter a product ID to test with.'));
            }
            $profile = clone $this->profileRepository->getById($profileId);
            $profile->setXslTemplate($template);
            $files = $this->feedExporter->test($profile, [$productId]);

            $output = [];
            foreach ($files as $filename => $content) {
                $output[] = 'File: ' . $filename . "\n\n" . $content;
            }
            if ($this->getRequest()->getPost('serve_to_browser') === 'true') {
                $this->testFileStorage->save($profileId, $this->getUserId(), $files);
            }
            return $result->setContents(implode("\n", $output));
        } catch (\Throwable $e) {
            return $result->setContents(__('Error: %1', $e->getMessage())->render());
        }
    }

    private function getUserId(): int
    {
        return (int)$this->_auth->getUser()?->getId();
    }
}
