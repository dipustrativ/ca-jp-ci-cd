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

namespace CarpetAvenue\FeedExport\Block\Adminhtml\Profile\Edit;

use CarpetAvenue\FeedExport\Api\ProfileRepositoryInterface;
use CarpetAvenue\FeedExport\Model\Export\CollectionBuilder;
use CarpetAvenue\FeedExport\Model\Export\FieldResolverFactory;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Exception\LocalizedException;

/**
 * Xtento-style XSL editor (Ace) with "Test XSL Template" and "Show fields available for export".
 */
class XslEditor extends Template
{
    /**
     * @var string
     */
    protected $_template = 'CarpetAvenue_FeedExport::profile/xsl_editor.phtml';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly ProfileRepositoryInterface $profileRepository,
        private readonly CollectionBuilder $collectionBuilder,
        private readonly FieldResolverFactory $fieldResolverFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getProfileId(): int
    {
        return (int)$this->getRequest()->getParam('profile_id');
    }

    /**
     * First product the saved profile exports, so a test works without typing an ID.
     */
    public function getDefaultTestId(): ?int
    {
        if (!$this->getProfileId()) {
            return null;
        }
        try {
            $profile = $this->profileRepository->getById($this->getProfileId());
            $collection = $this->collectionBuilder->build(
                $profile,
                $this->fieldResolverFactory->create(['xslTemplate' => $profile->getXslTemplate()])
            );
            $collection->setPageSize(1)->setCurPage(1);
            $productId = (int)$collection->getFirstItem()->getId();
            return $productId ?: null;
        } catch (LocalizedException $e) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgetConfig(): array
    {
        return [
            'profileId' => $this->getProfileId(),
            'aceBaseUrl' => $this->getViewFileUrl('CarpetAvenue_FeedExport::js/ace') . '/',
            'testUrl' => $this->getUrl('*/*/validateXslTemplate'),
            'downloadUrl' => $this->getUrl('*/*/downloadTestExport', ['profile_id' => $this->getProfileId()]),
            'fieldsUrl' => $this->getUrl('*/*/fields', [
                'profile_id' => $this->getProfileId(),
                'test_id' => '__TEST_ID__',
            ]),
        ];
    }
}
