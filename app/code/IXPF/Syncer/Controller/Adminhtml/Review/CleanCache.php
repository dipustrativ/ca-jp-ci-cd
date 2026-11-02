<?php

declare(strict_types=1);

namespace IXPF\Syncer\Controller\Adminhtml\Review;

use IXPF\Syncer\Services\Reviews\ReviewCacheCleaner;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultInterface;

/**
 * Behind the "Clean Review Cache" button in the review configuration.
 *
 * Clears only the review cache type, which is the same thing `bin/magento cache:clean
 * ixpf_reviews` does. Every write path already invalidates itself, so this is for the case the
 * button exists for: somebody changed the IXPF tables by hand.
 */
class CleanCache extends Action
{
    public const ADMIN_RESOURCE = 'IXPF_Syncer::product_reviews';

    public function __construct(
        Context $context,
        private readonly ReviewCacheCleaner $cacheCleaner
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $this->cacheCleaner->cleanAll();
        $this->messageManager->addSuccessMessage(__('The review cache has been cleaned.'));

        return $this->resultRedirectFactory->create()->setPath(
            'adminhtml/system_config/edit',
            ['section' => 'ixpf_reviews']
        );
    }
}
