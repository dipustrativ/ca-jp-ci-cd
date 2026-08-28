<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Controller\Adminhtml\Blacklist;

use CarpetAvenue\Orderify\Api\BlacklistInterface;
use Exception;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;

class Add extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_Orderify::add_blacklist';

    public function __construct(
        Context                      $context,
        protected BlacklistInterface $blacklistService,
    )
    {
        parent::__construct($context);
    }

    public function execute(): ResultInterface|ResponseInterface|Redirect
    {

        try {
            $orderId = $this->_request->getPost('order_id');
            $type = $this->_request->getPost('blacklist_type');
            $note = $this->_request->getPost('blacklist_reason');
            if (empty($orderId) && empty($type)) {
                throw new LocalizedException(
                    __('Order ID and Blacklist Type are required')
                );
            }
            $this->blacklistService->addToBlacklist((int)$orderId, $type, $note);

            $this->messageManager->addSuccessMessage(__("%1 has been added to the blacklist.", strtoupper($type)));
        } catch (Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        $resultRedirect = $this->resultRedirectFactory->create();
        return $resultRedirect->setRefererUrl();
    }

    /**
     * @return bool
     */
    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('CarpetAvenue_Orderify::add_blacklist');
    }


}
