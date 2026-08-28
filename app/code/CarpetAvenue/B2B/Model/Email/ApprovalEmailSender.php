<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Sends the "account approved" transactional email. Pattern mirrors
 * CarpetAvenue\Orderify\Model\Email\FailedPaymentSender.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Model\Email;

use CarpetAvenue\B2B\Model\Config;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class ApprovalEmailSender
{
    public function __construct(
        private readonly Config $config,
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function send(CustomerInterface $customer): bool
    {
        if (!$customer->getEmail()) {
            return false;
        }

        $storeId = (int)($customer->getStoreId() ?: $this->storeManager->getStore()->getId());

        if (!$this->config->sendApprovalEmail($storeId)) {
            return false;
        }

        try {
            $this->inlineTranslation->suspend();
            $store = $this->storeManager->getStore($storeId);
            $storeUrl = $store instanceof \Magento\Store\Model\Store ? $store->getBaseUrl() : '';
            $name = trim($customer->getFirstname() . ' ' . $customer->getLastname());

            $transport = $this->transportBuilder
                ->setTemplateIdentifier($this->config->emailTemplate($storeId))
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars([
                    'customer_name' => $name,
                    'store' => $store,
                    'store_url' => $storeUrl,
                ])
                ->setFromByScope($this->config->emailIdentity($storeId), $storeId)
                ->addTo($customer->getEmail(), $name)
                ->getTransport();

            $transport->sendMessage();
            $this->inlineTranslation->resume();

            return true;
        } catch (\Exception $e) {
            $this->inlineTranslation->resume();
            $this->logger->error('[CarpetAvenue_B2B] approval email error: ' . $e->getMessage());

            return false;
        }
    }
}
