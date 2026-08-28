<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * On storefront registration: assign the Business group (if enabled) and mark the
 * account pending approval (if approval is required). Both parts are switch-gated.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Observer;

use CarpetAvenue\B2B\Model\ApprovalChecker;
use CarpetAvenue\B2B\Model\Config;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class SetPendingOnRegister implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $setBusiness = $this->config->defaultToBusiness();
        $setPending = $this->config->requireApproval();
        if (!$setBusiness && !$setPending) {
            return;
        }

        $eventCustomer = $observer->getEvent()->getData('customer');
        if (!$eventCustomer || !$eventCustomer->getId()) {
            return;
        }

        try {
            $customer = $this->customerRepository->getById((int)$eventCustomer->getId());
            if ($setBusiness) {
                $customer->setGroupId(Config::BUSINESS_GROUP_ID);
            }
            if ($setPending) {
                $customer->setCustomAttribute(ApprovalChecker::ATTR_IS_APPROVED, 0);
            }
            $this->customerRepository->save($customer);
        } catch (\Exception $e) {
            $this->logger->error('[CarpetAvenue_B2B] register hook: ' . $e->getMessage());
        }
    }
}
