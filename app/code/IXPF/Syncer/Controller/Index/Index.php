<?php

declare(strict_types=1);

namespace IXPF\Syncer\Controller\Index;

use IXPF\Syncer\ViewModel\Reviews\FormEmbed;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * Review invite landing page: GET /reviews?token=...&sku=...&rating=...
 *
 * Storefront chrome wrapped around one iframe. Everything the customer types belongs to the
 * form's own origin and never reaches Magento. Only the token's shape is checked here - this
 * application cannot tell a live invite from an expired one, so the form makes that call and
 * answers 404 itself.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly FormEmbed $viewModel
    ) {
    }

    public function execute(): Page|Forward
    {
        // A malformed token means the link is not one we minted; the feature being off means the
        // page should not exist yet. Both are 404s - an error page would invite a retry, and a
        // redirect home would hide a broken campaign link behind a soft landing.
        if (!$this->viewModel->isAvailable()) {
            return $this->forwardFactory->create()->forward('noroute');
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('Write a review'));

        // One customer, one token, one visit. Indexing it would publish a working invite link.
        $page->getConfig()->setRobots('NOINDEX,NOFOLLOW');

        return $page;
    }
}
