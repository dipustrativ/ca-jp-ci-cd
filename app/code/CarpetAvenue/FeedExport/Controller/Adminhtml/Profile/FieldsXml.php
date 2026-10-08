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
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * Raw intermediate XML of one product, as the XSL template receives it.
 */
class FieldsXml extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_FeedExport::profile';

    public function __construct(
        Context $context,
        private readonly ProfileRepositoryInterface $profileRepository,
        private readonly FeedExporterInterface $feedExporter
    ) {
        parent::__construct($context);
    }

    public function execute(): Raw
    {
        /** @var Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        try {
            $xml = $this->feedExporter->getSourceXml(
                $this->profileRepository->getById((int)$this->getRequest()->getParam('profile_id')),
                [(int)$this->getRequest()->getParam('test_id')]
            );
            return $result->setHeader('Content-Type', 'text/xml; charset=UTF-8', true)->setContents($xml);
        } catch (LocalizedException $e) {
            return $result->setHeader('Content-Type', 'text/plain; charset=UTF-8', true)
                ->setContents($e->getMessage());
        }
    }
}
