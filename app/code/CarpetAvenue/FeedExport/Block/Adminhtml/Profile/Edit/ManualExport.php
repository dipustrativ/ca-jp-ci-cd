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
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * "Manual Export" tab: run the saved profile now.
 */
class ManualExport extends Template
{
    /**
     * @var string
     */
    protected $_template = 'CarpetAvenue_FeedExport::profile/manual_export.phtml';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly ProfileRepositoryInterface $profileRepository,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getProfileId(): int
    {
        return (int)$this->getRequest()->getParam('profile_id');
    }

    public function canRun(): bool
    {
        return $this->getProfileId() > 0 && $this->_authorization->isAllowed('CarpetAvenue_FeedExport::run');
    }

    public function getRunUrl(): string
    {
        return $this->getUrl('*/*/run', ['profile_id' => $this->getProfileId(), 'from_edit' => 1]);
    }

    public function getLastExecution(): ?string
    {
        try {
            $lastExecution = $this->profileRepository->getById($this->getProfileId())->getLastExecution();
        } catch (NoSuchEntityException $e) {
            return null;
        }
        return $lastExecution ? $this->formatDate($lastExecution, \IntlDateFormatter::MEDIUM, true) : null;
    }
}
