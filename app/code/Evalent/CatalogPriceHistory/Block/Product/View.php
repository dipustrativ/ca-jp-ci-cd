<?php


namespace Evalent\CatalogPriceHistory\Block\Product;

class View extends \Magento\Catalog\Block\Product\View
{
    /** @var \Evalent\CatalogPriceHistory\Api\HistoryRepositoryInterface */
    protected $priceHistoryRepository;

    public function __construct(
        \Magento\Catalog\Block\Product\Context $context, \Magento\Framework\Url\EncoderInterface $urlEncoder,
        \Magento\Framework\Json\EncoderInterface $jsonEncoder,
        \Magento\Framework\Stdlib\StringUtils $string,
        \Magento\Catalog\Helper\Product $productHelper,
        \Magento\Catalog\Model\ProductTypes\ConfigInterface $productTypeConfig,
        \Magento\Framework\Locale\FormatInterface $localeFormat,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency,
        \Evalent\CatalogPriceHistory\Api\HistoryRepositoryInterface $priceHistoryRepository,
        array $data = [])
    {
        parent::__construct($context, $urlEncoder, $jsonEncoder, $string, $productHelper, $productTypeConfig, $localeFormat, $customerSession, $productRepository, $priceCurrency, $data);
        $this->priceHistoryRepository = $priceHistoryRepository;
    }

    /**
     * @return float|null
     */
    public function getLowestPrice(){
        return $this->priceHistoryRepository->getLowestPrice(
            $this->getProduct(),
            $this->_storeManager->getStore()->getId(),
            $this->_storeManager->getStore()->getWebsiteId(),
            $this->customerSession->getCustomerGroupId()
        );
    }

    public function getStoreId(): int
    {
        return (int)$this->_storeManager->getStore()->getId();
    }

    /**
     * @return int
     */
    public function getLowestPriceDays(){
        return (int)$this->priceHistoryRepository->getLowestPriceDays($this->_storeManager->getStore()->getId());
    }

    /**
     * @return \Magento\Framework\Phrase
     */
    public function getLowestPriceLabel(){
        return __('Lowest price latest %1 days', $this->getLowestPriceDays());
    }

    /**
     * Convert and format price value for specified store
     *
     * @param   float $value
     * @param   int|\Magento\Store\Model\Store $store
     * @param   bool $format
     * @param   bool $includeContainer
     * @return  float|string
     */
    public function currencyByStore($value, $store = null, $format = true, $includeContainer = true)
    {
        if ($format) {
            if ('configurable'=== $this->getProduct()->getTypeId()){
                $value = $this->formatCurrency(
                    $value,
                    $store,
                    $includeContainer
                );
            }
            else{
                $value = $this->priceCurrency->convertAndFormat(
                    $value,
                    $includeContainer,
                    \Magento\Framework\Pricing\PriceCurrencyInterface::DEFAULT_PRECISION,
                    $store
                );    
            }
        } else {
            if ('configurable' !== $this->getProduct()->getTypeId()){
                $value = $this->priceCurrency->convert($value, $store);
            }
        }

        return $value;
    }
    /**
     * Convert and format price value for specified store
     *
     * @param   float $value
     * @param   int|\Magento\Store\Model\Store $store
     * @param   bool $format
     * @param   bool $includeContainer
     * @return  float|string
     */
    public function formatCurrency($value, $store = null, $includeContainer = true)
    {
        return $this->priceCurrency->format(
            $value,
            $includeContainer,
            \Magento\Framework\Pricing\PriceCurrencyInterface::DEFAULT_PRECISION,
            $store
        );

    }
}
