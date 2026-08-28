<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Controller\Adminhtml\Blacklist;

use CarpetAvenue\Orderify\Api\BlacklistInterface;
use Exception;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;

class Remove extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_Orderify::remove_blacklist';

    public function __construct(
        Context $context,
        protected BlacklistInterface $blacklistService
    )
    {
        parent::__construct($context);
    }

    public function execute(): ResultInterface|ResponseInterface|Redirect
    {
        try {
            $id = (int)$this->getRequest()->getParam('id');
            if (empty($id)) {
                throw new LocalizedException(
                    __('Blacklist ID is required')
                );
            }
            $this->blacklistService->removeBlackList($id);

            $this->messageManager->addSuccessMessage(__('Removed from blacklist successfully.'));
        } catch (Exception $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        $resultRedirect = $this->resultRedirectFactory->create();
        return $resultRedirect->setRefererUrl();
    }

    /**
     * @return bool
     */
    public function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed('CarpetAvenue_Orderify::remove_blacklist');
    }
}