<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Friendly UX guard: redirects an unapproved logged-in customer away from the
 * checkout page(s) with the configured pending message. The authoritative block
 * is PlaceOrderGuard; this just avoids a dead-end checkout screen.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Observer;

use CarpetAvenue\B2B\Model\ApprovalChecker;
use CarpetAvenue\B2B\Model\Config;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\UrlInterface;

class BlockCheckout implements ObserverInterface
{
    public function __construct(
        private readonly ApprovalChecker $approvalChecker,
        private readonly Config $config,
        private readonly CustomerSession $customerSession,
        private readonly MessageManager $messageManager,
        private readonly ActionFlag $actionFlag,
        private readonly UrlInterface $url
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->customerSession->isLoggedIn()) {
            return;
        }
        if (!$this->approvalChecker->isBlocked((int)$this->customerSession->getCustomerId())) {
            return;
        }

        $this->messageManager->addErrorMessage(
            $this->config->pendingMessage() ?: (string)__('Your account is pending approval.')
        );

        $action = $observer->getEvent()->getData('controller_action');
        $this->actionFlag->set('', \Magento\Framework\App\Action\Action::FLAG_NO_DISPATCH, true);

        if ($action && ($response = $action->getResponse()) instanceof HttpResponse) {
            $response->setRedirect($this->url->getUrl('checkout/cart'));
        }
    }
}
