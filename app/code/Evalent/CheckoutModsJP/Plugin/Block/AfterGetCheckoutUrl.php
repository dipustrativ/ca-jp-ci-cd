<?php

declare(strict_types=1);

namespace Evalent\CheckoutModsJP\Plugin\Block;

/**
 * Forces every "checkout" entry point (minicart, cart sidebar, onepage link)
 * to land on the cart page, where the customer picks a payment button.
 */
class AfterGetCheckoutUrl
{
    /**
     * @var \Magento\Framework\UrlInterface
     */
    private $urlBuilder;

    public function __construct(
        \Magento\Framework\UrlInterface $urlBuilder
    ) {
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * @param \Magento\Framework\View\Element\Template $subject
     * @param string $result
     * @return string
     */
    public function afterGetCheckoutUrl(\Magento\Framework\View\Element\Template $subject, $result)
    {
        return $this->urlBuilder->getUrl('checkout/cart', []);
    }
}
