<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * View model / block for the reseller application form: supplies select options,
 * the post URL and any retained values after a validation redirect.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Block\Reseller;

use CarpetAvenue\B2B\Model\Config;
use CarpetAvenue\B2B\Model\Source\BusinessType;
use CarpetAvenue\B2B\Model\Source\SalesChannel;
use Magento\Cms\Api\GetBlockByIdentifierInterface;
use Magento\Cms\Model\Template\FilterProvider;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class Form extends Template
{
    public function __construct(
        Context $context,
        private readonly BusinessType $businessType,
        private readonly SalesChannel $salesChannel,
        private readonly CustomerSession $customerSession,
        private readonly Config $config,
        private readonly GetBlockByIdentifierInterface $getBlockByIdentifier,
        private readonly FilterProvider $filterProvider,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getFormAction(): string
    {
        return $this->getUrl('become-a-carpient-reseller/index/post');
    }

    /**
     * Whether the reCAPTCHA widget should render (master switch, default Yes).
     */
    public function isRecaptchaEnabled(): bool
    {
        return $this->config->resellerRecaptchaEnabled();
    }

    /**
     * Intro/description HTML pulled from the configured CMS block (identifier),
     * with CMS directives/widgets processed. Empty string if the block is absent.
     */
    public function getIntroHtml(): string
    {
        $storeId = (int)$this->_storeManager->getStore()->getId();

        try {
            $cmsBlock = $this->getBlockByIdentifier->execute($this->config->resellerIntroBlock($storeId), $storeId);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            return '';
        }

        if (!$cmsBlock->isActive()) {
            return '';
        }

        return $this->filterProvider->getBlockFilter()
            ->setStoreId($storeId)
            ->filter((string)$cmsBlock->getContent());
    }

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function getBusinessTypeOptions(): array
    {
        return $this->businessType->toOptionArray();
    }

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function getSalesChannelOptions(): array
    {
        return $this->salesChannel->toOptionArray();
    }

    /**
     * Value retained after a failed submit (see Controller\Reseller\Post::keepData).
     */
    public function getValue(string $field): string
    {
        $data = (array)$this->customerSession->getResellerFormData();

        return isset($data[$field]) ? (string)$data[$field] : '';
    }

    /**
     * Clears retained form data once the form has been rendered.
     */
    protected function _afterToHtml($html): string
    {
        $this->customerSession->unsResellerFormData();

        return parent::_afterToHtml($html);
    }
}
