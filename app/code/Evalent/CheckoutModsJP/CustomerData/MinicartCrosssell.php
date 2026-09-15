<?php

declare(strict_types=1);

namespace Evalent\CheckoutModsJP\CustomerData;

use Magento\Checkout\Block\Cart\Crosssell;
use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Framework\Pricing\Render;
use Magento\Framework\View\LayoutFactory;
use Psr\Log\LoggerInterface;

/**
 * Renders the cart cross-sell block as a customer-data section so the minicart
 * can show it. The minicart is knockout-rendered from customer data, so it has
 * no server-side block of its own — the HTML has to travel with the section.
 */
class MinicartCrosssell implements SectionSourceInterface
{
    /**
     * Same template the cart page uses, minus the wishlist/compare links that
     * make no sense inside the dropdown.
     */
    private const TEMPLATE = 'Evalent_CheckoutModsJP::product/list/minicart-crosssell.phtml';

    public function __construct(
        private readonly LayoutFactory $layoutFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getSectionData(): array
    {
        return ['minicart_crosssell' => $this->renderBlock()];
    }

    private function renderBlock(): string
    {
        try {
            $layout = $this->layoutFactory->create();

            // AbstractProduct::getProductPriceHtml() looks this block up by name and
            // silently returns '' when it is missing. A bare layout has no handles
            // loaded, so create it here or every cross-sell renders without a price.
            $layout->createBlock(
                Render::class,
                'product.price.render.default',
                ['data' => ['price_render_handle' => 'catalog_product_prices']]
            );

            return (string)$layout->createBlock(Crosssell::class)
                ->addData([
                    'type' => 'crosssell',
                    'block_title' => __('We recommend the following products:'),
                    'show_cart' => true,
                ])
                ->setTemplate(self::TEMPLATE)
                ->toHtml();
        } catch (\Throwable $e) {
            // A broken cross-sell must never take the minicart down with it.
            $this->logger->error('Evalent_CheckoutModsJP minicart cross-sell: ' . $e->getMessage(), ['exception' => $e]);
            return '';
        }
    }
}
