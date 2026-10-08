<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_FeedExport
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\FeedExport\Controller\Adminhtml\Profile;

use CarpetAvenue\FeedExport\Api\ProfileRepositoryInterface;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Result\PageFactory;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_FeedExport::profile';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly ProfileRepositoryInterface $profileRepository
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $profileId = (int)$this->getRequest()->getParam('profile_id');
        $title = __('New Feed Profile');
        if ($profileId) {
            try {
                $title = __('Edit Feed Profile "%1"', $this->profileRepository->getById($profileId)->getName());
            } catch (NoSuchEntityException $e) {
                $this->messageManager->addErrorMessage(__('This feed profile no longer exists.'));
                return $this->resultRedirectFactory->create()->setPath('*/*/index');
            }
        }

        $page = $this->pageFactory->create();
        $page->setActiveMenu('CarpetAvenue_FeedExport::profile');
        $page->getConfig()->getTitle()->prepend($title);
        return $page;
    }
}
