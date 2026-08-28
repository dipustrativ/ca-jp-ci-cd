<?php
declare(strict_types=1);

namespace CarpetAvenue\Productify\Block\Pricing\Render;

use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Pricing\Render\Amount as AmountAlias;
use Magento\Framework\Pricing\Render\RendererPool;
use Magento\Framework\Pricing\SaleableInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\ScopeInterface;

class Amount extends AmountAlias
{
    const TAX_FREE_GROUP_ID = 3;
    const PRICE_INCLUDE_TAX = 2;

    /**
     * @var HttpContext
     */
    private HttpContext $httpContext;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @param Context $context
     * @param AmountInterface $amount
     * @param PriceCurrencyInterface $priceCurrency
     * @param RendererPool $rendererPool
     * @param HttpContext $httpContext
     * @param ScopeConfigInterface $scopeConfig
     * @param SaleableInterface|null $saleableItem
     * @param PriceInterface|null $price
     * @param array $data
     */
    public function __construct(
        Template\Context       $context,
        AmountInterface        $amount,
        PriceCurrencyInterface $priceCurrency,
        RendererPool           $rendererPool,
        HttpContext            $httpContext,
        ScopeConfigInterface   $scopeConfig,
        ?SaleableInterface      $saleableItem = null,
        ?PriceInterface         $price = null,
        array                  $data = []
    )
    {
        parent::__construct($context, $amount, $priceCurrency, $rendererPool, $saleableItem, $price, $data);
        $this->httpContext = $httpContext;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Check if customer is in Tax Free group
     *
     * Group comes from the HTTP context, not Customer\Model\Session: the session
     * must not be touched on an FPC-cached page, and FPC already varies on this.
     *
     * @return bool
     */
    public function isCustomerInTaxFreeGroup(): bool
    {
        $groupId = $this->httpContext->getValue(CustomerContext::CONTEXT_GROUP);

        return (int)$groupId === self::TAX_FREE_GROUP_ID;
    }

    /**
     * Check if Display Product Prices In Catalog set Including Tax
     *
     * @return bool
     */
    public function isPricesIncludeTax(): bool
    {
        return $this->scopeConfig->getValue('tax/display/type', ScopeInterface::SCOPE_STORE) == self::PRICE_INCLUDE_TAX;
    }

    /**
     * Get vat incl. text from core_config_data
     *
     * @return mixed
     */
    public function getVatText(): mixed
    {
        return $this->scopeConfig->getValue('tax/display/vat_text', ScopeInterface::SCOPE_STORE);
    }

    public function getExclVatText()
    {
        return $this->scopeConfig->getValue('tax/display/excl_vat_text', ScopeInterface::SCOPE_STORE);
    }
}
