<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Renders a single reseller application in the admin.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Block\Adminhtml\Reseller;

use CarpetAvenue\B2B\Model\ResellerApplication;
use CarpetAvenue\B2B\Model\Source\ApplicationStatus;
use CarpetAvenue\B2B\Model\Source\BusinessType;
use CarpetAvenue\B2B\Model\Source\SalesChannel;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;

class View extends Template
{
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly BusinessType $businessType,
        private readonly SalesChannel $salesChannel,
        private readonly ApplicationStatus $applicationStatus,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getApplication(): ?ResellerApplication
    {
        $application = $this->registry->registry('carpetavenue_b2b_reseller_application');

        return $application instanceof ResellerApplication ? $application : null;
    }

    public function getBusinessTypeLabel(string $value): string
    {
        return $this->label($this->businessType->toOptionArray(), $value);
    }

    public function getSalesChannelLabel(string $value): string
    {
        return $this->label($this->salesChannel->toOptionArray(), $value);
    }

    public function getStatusLabel(string $value): string
    {
        return $this->label($this->applicationStatus->toOptionArray(), $value);
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('carpetavenue_b2b/reseller/index');
    }

    /**
     * @param array<int, array{value: string, label: \Magento\Framework\Phrase}> $options
     */
    private function label(array $options, string $value): string
    {
        foreach ($options as $option) {
            if ((string)$option['value'] === $value) {
                return (string)$option['label'];
            }
        }

        return $value;
    }
}
