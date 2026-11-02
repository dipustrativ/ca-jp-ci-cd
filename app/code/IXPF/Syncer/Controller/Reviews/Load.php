<?php

declare(strict_types=1);

namespace IXPF\Syncer\Controller\Reviews;

use IXPF\Syncer\Helper\Logger\ReviewLogger;
use IXPF\Syncer\ViewModel\Reviews\Reviews;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutFactory;
use Throwable;

/**
 * Renders one page of the review drawer.
 *
 * GET /ixpfsyncer/reviews/load?product_id=123&page=1&sort=highest&has_photos=1
 *
 * Three path segments, so no underscore in the URL - that trick only applies to the module's
 * nested V1_* controllers, where a domain directory sits between the version and the action.
 *
 * Returns rendered HTML rather than raw review data. The markup lives in the active theme and
 * there is exactly one copy of it, instead of a .phtml for the first page and a JS template for
 * every page after.
 *
 * The response is deliberately uncacheable. The provider already caches its own results under a
 * tag the upsert cleans, so a second HTTP cache in front would only add a way for a stale page to
 * outlive a push.
 */
class Load implements HttpGetActionInterface
{
    public function __construct(
        private readonly JsonFactory $resultFactory,
        private readonly LayoutFactory $layoutFactory,
        private readonly Reviews $viewModel,
        private readonly ReviewLogger $logger
    ) {
    }

    public function execute(): Json
    {
        $result = $this->resultFactory->create();
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate', true);

        try {
            // An unknown product, a product with no pool, or the feature switched off all mean
            // the same thing to the caller: nothing to show. Not an error.
            if (!$this->viewModel->isFrontendEnabled() || $this->viewModel->getReviewGroup() === null) {
                return $result->setData([
                    'items_html'   => '',
                    'summary_html' => '',
                    'total'        => 0,
                    'page'         => 1,
                    'page_count'   => 0,
                    'filters'      => ['photos' => false, 'text' => false],
                ]);
            }

            $list = $this->viewModel->getList();

            return $result->setData([
                'items_html'   => $this->render('IXPF_Syncer::reviews/list.phtml'),
                'summary_html' => $this->render('IXPF_Syncer::reviews/summary.phtml'),
                'total'        => $list->getTotalCount(),
                'page'         => $list->getPage(),
                'page_count'   => $list->getPageCount(),
                // Pool-level, never filtered: which checkboxes the drawer may reveal.
                'filters'      => $this->viewModel->getFilterAvailability(),
            ]);
        } catch (Throwable $e) {
            $this->logger->writeFailureDaily($e, 'Review drawer load failed');

            return $result->setHttpResponseCode(500)->setData(['message' => __('Could not load reviews.')]);
        }
    }

    /**
     * The view model is passed as block data under the same key the layout `view_model` argument
     * uses, so a template renders identically from layout XML and from here.
     */
    private function render(string $template): string
    {
        return $this->layoutFactory->create()
            ->createBlock(Template::class)
            ->setTemplate($template)
            ->setData('view_model', $this->viewModel)
            ->toHtml();
    }
}
