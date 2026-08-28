<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Notifies the admin of a new reseller application. Pattern mirrors
 * CarpetAvenue\B2B\Model\Email\ApprovalEmailSender.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Model\Email;

use CarpetAvenue\B2B\Model\Config;
use CarpetAvenue\B2B\Model\ResellerApplication;
use CarpetAvenue\B2B\Model\Source\BusinessType;
use CarpetAvenue\B2B\Model\Source\SalesChannel;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class ResellerApplicationSender
{
    public function __construct(
        private readonly Config $config,
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation,
        private readonly StoreManagerInterface $storeManager,
        private readonly BusinessType $businessType,
        private readonly SalesChannel $salesChannel,
        private readonly LoggerInterface $logger
    ) {
    }

    public function send(ResellerApplication $application, int $storeId): bool
    {
        if (!$this->config->resellerEmailEnabled($storeId)) {
            return false;
        }

        $recipient = $this->config->resellerRecipient($storeId);
        if ($recipient === '') {
            $this->logger->warning('[CarpetAvenue_B2B] reseller notification skipped: no recipient configured');
            return false;
        }

        try {
            $this->inlineTranslation->suspend();

            $transport = $this->transportBuilder
                ->setTemplateIdentifier($this->config->resellerEmailTemplate($storeId))
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars([
                    'name' => (string)$application->getData('name'),
                    'email' => (string)$application->getData('email'),
                    'company_name' => (string)$application->getData('company_name'),
                    'homepage' => (string)$application->getData('homepage'),
                    'business_type' => $this->label($this->businessType->toOptionArray(), (string)$application->getData('business_type')),
                    'sales_channels' => $this->label($this->salesChannel->toOptionArray(), (string)$application->getData('sales_channels')),
                    'message' => (string)$application->getData('message'),
                    'application_id' => (int)$application->getData('application_id'),
                ])
                ->setFromByScope($this->config->resellerEmailIdentity($storeId), $storeId)
                ->addTo($recipient)
                ->getTransport();

            $transport->sendMessage();
            $this->inlineTranslation->resume();

            return true;
        } catch (\Exception $e) {
            $this->inlineTranslation->resume();
            $this->logger->error('[CarpetAvenue_B2B] reseller notification error: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Resolve a stored option value to its human label.
     *
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
