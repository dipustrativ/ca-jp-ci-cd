<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Fires the approval email when a customer's is_approved flag transitions 0 -> 1.
 * Because approval is always performed through the customer repository (admin
 * form save or mass-approve controller), this single hook covers every path.
 * Gated by "Send Email On Approval".
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Observer;

use CarpetAvenue\B2B\Model\ApprovalChecker;
use CarpetAvenue\B2B\Model\Config;
use CarpetAvenue\B2B\Model\Email\ApprovalEmailSender;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class SendApprovalEmail implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ApprovalChecker $approvalChecker,
        private readonly ApprovalEmailSender $emailSender
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->sendApprovalEmail()) {
            return;
        }

        /** @var CustomerInterface|null $customer */
        $customer = $observer->getEvent()->getData('customer');
        if (!$customer instanceof CustomerInterface) {
            return;
        }

        /** @var CustomerInterface|null $orig */
        $orig = $observer->getEvent()->getData('orig_customer_data_object');

        $nowApproved = $this->approvalChecker->isApprovedCustomer($customer);
        $wasApproved = $orig instanceof CustomerInterface && $this->approvalChecker->isApprovedCustomer($orig);

        if ($nowApproved && !$wasApproved) {
            $this->emailSender->send($customer);
        }
    }
}
