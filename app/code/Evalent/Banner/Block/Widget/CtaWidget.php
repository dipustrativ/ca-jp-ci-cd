<?php
namespace Evalent\Banner\Block\Widget;

use Evalent\Banner\Api\BannerRepositoryInterface;
use Evalent\Banner\Api\Data\BannerInterface;
use Evalent\Banner\Model\ResourceModel\Banner\CollectionFactory;
use Magento\Framework\View\Element\Template;
use Magento\Widget\Block\BlockInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Block\Product\AbstractProduct;
use Psr\Log\LoggerInterface;

class CtaWidget extends BannerBlock
{
    const LOGGER_PREFIX = 'CtaBanner Block: ';
    protected $_template = "widget/cta_banner.phtml";

    private $productRepository;

    private $abstractProduct;

    /** @var BannerInterface|null */
    protected $banner;

    public function __construct(
        LoggerInterface           $logger,
        CollectionFactory         $bannerCollectionFactory,
        BannerRepositoryInterface $bannerRepository,
        Template\Context          $context,
        ProductRepositoryInterface $productRepository,
        AbstractProduct             $abstractProduct,
        array                     $data = []
    ) {
        parent::__construct($logger, $bannerCollectionFactory, $bannerRepository, $context, $data);

        $this->productRepository = $productRepository;
        $this->abstractProduct = $abstractProduct;
    }

    protected function _toHtml(): string
    {
        if ($this->getBanner() == null) {
            return '';
        }
        return parent::_toHtml();
    }


    /**
     * @return \Evalent\Banner\Api\Data\BannerInterface|null
     */
    public function getBanner(): ?BannerInterface
    {
        if ($this->banner != null) {
            return $this->banner;
        }
        $bannerId = $this->getData('banner');
        if (!$bannerId) {
            return null;
        }
        try {
            $banner = $this->bannerRepository->get($bannerId);
            if (!$banner->getEnabled()) {
                $this->logger->info(self::LOGGER_PREFIX . "Banner with ID " . $this->getData('banner') . " is disabled and will not show.");
                return null;
            }
            $this->banner = $banner;
            return $this->banner;
        } catch (LocalizedException $e) {
            $this->logger->error(self::LOGGER_PREFIX . "Couldn't find banner with ID " . $this->getData('banner'));
            return null;
        }
    }

    public function getProductBySku($sku)
    {
        $_product = null;

        try {

            $_product = $this->productRepository->get($sku);
        } catch (NoSuchEntityException $e) {

            $this->_logger->critical($e->getMessage());
        }

        return $_product;
    }

    public function getProductPrice($product)
    {
        $_priceHtml = "";

        try {

            $_priceHtml = $this->abstractProduct->getProductPrice($product);

        } catch (NoSuchEntityException $e) {

            $this->_logger->critical($e->getMessage());
        }


        return $_priceHtml;
    }

    public function getDotX()
    {
        return $this->getData('dot_x');
    }

    public function getDotY()
    {
        return $this->getData('dot_y');
    }

    public function getProductSku()
    {
        return $this->getData('product_sku');
    }

    /**
     * Get value of widgets' buttoncolor parameter
     *
     * @return mixed|string
     */
    public function getButtonColor()
    {
        return $this->getData('buttoncolor');
    }

    /**
     * Get value of widgets' textcolor_desc parameter
     *
     * @return mixed|string
     */
    public function getDescColor()
    {
        return $this->getData('textcolor_desc');
    }

    /**
     * Get value of widgets' textcolor_button parameter
     *
     * @return mixed|string
     */
    public function getButtonTextColor()
    {
        return $this->getData('textcolor_button');
    }

    /**
     * Get value of widgets' textcolor parameter
     *
     * @return mixed|string
     */
    public function getTextColor()
    {
        return $this->getData('textcolor');
    }


}
