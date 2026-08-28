<?php

namespace Evalent\CatalogPriceHistory\Controller\Product;

use Evalent\CatalogPriceHistory\Api\HistoryRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use \Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Customer\Model\Session as CustomerSession;

class Ajax extends Action
{
    public function __construct(
        Context $context,
        protected RawFactory $_rawFactory,
        protected StoreManagerInterface $_storeManager,
        protected CacheInterface $_cache,
        protected PriceCurrencyInterface $_priceCurrency,
        protected ProductRepositoryInterface $productRepository,
        protected HistoryRepositoryInterface $priceHistoryRepository,
        protected CustomerSession $customerSession
    )
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $productId = (int)$this->getRequest()->getParam('id');
        $cacheKey = 'pricehistory_product_details_' . $productId . "_store_" . $this->_storeManager->getStore()->getId();
        if (!$productId) {
            return $this->_rawFactory->create()->setContents('');
        }

        $cacheExits = $this->_cache->load($cacheKey);
        if ($cacheExits) {
            // Add Extra data in header to identify cache usage
            $this->getResponse()->setHeader('X-Cache-Pricehistory-Product-Details', 'HIT');
            return $this->_rawFactory->create()->setContents($cacheExits);
        } else {
            $this->getResponse()->setHeader('X-Cache-Pricehistory-Product-Details', 'MISS');
        }

        $price = $this->getLowestPrice();
        if (intval($price) <= 0) {
            return $this->_rawFactory->create()->setContents('');
        }

        $html = '<div class="label"> '. $this->getLowestPriceLabel(). ":&nbsp;".'</div>'. $this->currencyByStore($price).'<span> '.__("Incl. VAT").'</span>';

        //-- Cache similar product
        $this->_cache->save(
            $html,
            $cacheKey,
            [Product::CACHE_TAG . '_' . $productId]
        );
        $resultRaw = $this->_rawFactory->create();
        return $resultRaw->setContents($html);
    }

    public function getProduct()
    {
        return $this->productRepository->getById($this->getRequest()->getParam('id'));
    }

    public function getLowestPrice()
    {
        return $this->priceHistoryRepository->getLowestPrice(
            $this->getProduct(),
            $this->_storeManager->getStore()->getId(),
            $this->_storeManager->getStore()->getWebsiteId(),
            $this->customerSession->getCustomerGroupId()
        );
    }

    public function getLowestPriceDays()
    {
        return (int)$this->priceHistoryRepository->getLowestPriceDays($this->_storeManager->getStore()->getId());
    }

    public function getLowestPriceLabel()
    {
        return __('Lowest price latest %1 days', $this->getLowestPriceDays());
    }

    public function currencyByStore($value, $format = true, $includeContainer = true)
    {
        $store = $this->_storeManager->getStore()->getId();
        if ($format) {
            if ('configurable'=== $this->getProduct()->getTypeId()){
                $value = $this->formatCurrency(
                    $value,
                    $store,
                    $includeContainer
                );
            }
            else{
                $value = $this->_priceCurrency->convertAndFormat(
                    $value,
                    $includeContainer,
                    \Magento\Framework\Pricing\PriceCurrencyInterface::DEFAULT_PRECISION,
                    $store
                );
            }
        } else {
            if ('configurable' !== $this->getProduct()->getTypeId()){
                $value = $this->_priceCurrency->convert($value, $store);
            }
        }

        return $value;
    }

    public function formatCurrency($value, $store = null, $includeContainer = true)
    {
        return $this->_priceCurrency->format(
            $value,
            $includeContainer,
            \Magento\Framework\Pricing\PriceCurrencyInterface::DEFAULT_PRECISION,
            $store
        );

    }
}