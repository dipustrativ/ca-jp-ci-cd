<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Renders the "pending approval" banner on customer account pages. Only shows for
 * a logged-in, unapproved customer while the approval gate is active.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Block\Account;

use CarpetAvenue\B2B\Model\ApprovalChecker;
use CarpetAvenue\B2B\Model\Config;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class PendingNotice extends Template
{
    public function __construct(
        Context $context,
        private readonly CustomerSession $customerSession,
        private readonly ApprovalChecker $approvalChecker,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function shouldShow(): bool
    {
        if (!$this->customerSession->isLoggedIn()) {
            return false;
        }
        return $this->approvalChecker->isBlocked((int)$this->customerSession->getCustomerId());
    }

    public function getMessage(): string
    {
        $message = $this->config->pendingMessage();

        return $message !== ''
            ? (string)__($message)
            : (string)__('Your account is pending approval.');
    }
}
