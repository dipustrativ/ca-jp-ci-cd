<?php

namespace CarpetAvenue\Productify\Plugin;

use Magento\Catalog\Block\Product\AbstractProduct;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;

class CollectorTagPlugin
{
    /**
     * Renderer reused across tiles — this runs once per product tile, so
     * creating a block per call costs one object-manager create + interceptor
     * per product on the page.
     */
    private ?Template $renderer = null;

    public function __construct(
        protected LayoutInterface $layout
    )
    {
    }

    public function afterGetProductDetailsHtml(AbstractProduct $subject, $result, Product $product)
    {
        if ($this->renderer === null) {
            $this->renderer = $this->layout->createBlock(Template::class);
            $this->renderer->setTemplate('CarpetAvenue_Productify::tag/collector.phtml');
        }

        $this->renderer->setData('product', $product);

        return $result . $this->renderer->toHtml();
    }
}