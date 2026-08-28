<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Customer grid mass action: approve the selected customers. Approval is applied
 * through the customer repository so the is_approved 0 -> 1 transition fires
 * CarpetAvenue\B2B\Observer\SendApprovalEmail.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Controller\Adminhtml\Customer;

use CarpetAvenue\B2B\Model\ApprovalChecker;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Ui\Component\MassAction\Filter;

class MassApprove extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_B2B::approve_customer';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly CollectionFactory $collectionFactory,
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $collection = $this->filter->getCollection($this->collectionFactory->create());

        $approved = 0;
        $failed = 0;
        foreach ($collection->getAllIds() as $customerId) {
            try {
                $customer = $this->customerRepository->getById((int)$customerId);
                $attr = $customer->getCustomAttribute(ApprovalChecker::ATTR_IS_APPROVED);
                if ($attr !== null && (int)$attr->getValue() === 1) {
                    continue; // already approved – skip (no duplicate email)
                }
                $customer->setCustomAttribute(ApprovalChecker::ATTR_IS_APPROVED, 1);
                $this->customerRepository->save($customer);
                $approved++;
            } catch (\Exception $e) {
                $failed++;
            }
        }

        if ($approved > 0) {
            $this->messageManager->addSuccessMessage(
                (string)__('%1 customer(s) were approved.', $approved)
            );
        }
        if ($failed > 0) {
            $this->messageManager->addErrorMessage(
                (string)__('%1 customer(s) could not be approved.', $failed)
            );
        }

        /** @var \Magento\Backend\Model\View\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultRedirectFactory->create();
        return $resultRedirect->setPath('customer/index/index');
    }
}
