<?php
/**
 * BelVG LLC.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the EULA
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://store.belvg.com/BelVG-LICENSE-COMMUNITY.txt
 *
 ********************************************************************
 * @category   BelVG
 * @package    BelVG_GuestWishlist
 * @copyright  Copyright (c) BelVG LLC. (http://www.belvg.com)
 * @license    http://store.belvg.com/BelVG-LICENSE-COMMUNITY.txt
 */
namespace BelVG\GuestWishlist\CustomerData;
use Magento\Catalog\Model\Product\Image\NotLoadInfoImageException;

/**
 * Wishlist section
 */
class Wishlist extends \Magento\Wishlist\CustomerData\Wishlist
{
    /**
     * @var \BelVG\GuestWishlist\Helper\Wishlist
     */
    protected $wishlistProductHelper;

    /**
     * Wishlist constructor.
     * @param \Magento\Wishlist\Helper\Data $wishlistHelper
     * @param \Magento\Wishlist\Block\Customer\Sidebar $block
     * @param \Magento\Catalog\Helper\ImageFactory $imageHelperFactory
     * @param \Magento\Framework\App\ViewInterface $view
     * @param \BelVG\GuestWishlist\Helper\Wishlist $wishlistProductHelper
     */
    public function __construct(
        \Magento\Wishlist\Helper\Data $wishlistHelper,
        \Magento\Wishlist\Block\Customer\Sidebar $block,
        \Magento\Catalog\Helper\ImageFactory $imageHelperFactory,
        \Magento\Framework\App\ViewInterface $view,
        \BelVG\GuestWishlist\Helper\Wishlist $wishlistProductHelper
    ) {
        parent::__construct($wishlistHelper, $block, $imageHelperFactory, $view);
        $this->wishlistProductHelper = $wishlistProductHelper;
    }


    /**
     * {@inheritdoc}
     */
    public function getSectionData()
    {
        $counter = $this->getCounter();
        $items = $counter ? $this->_getItems() : [];

        if($counter)
            $sideBarItems = array_slice($items, 0, self::SIDEBAR_ITEMS_NUMBER);
        else
            $sideBarItems = $items;
        return [
            'counter' => $counter,
            'productIds'=> $this->wishlistProductHelper->getProductIds(),
            'items' => $sideBarItems,
            'modalItems' => $items,
        ];
    }


    /**
     * Get wishlist items
     *
     * @return array
     */
    protected function _getItems()
    {
        $this->view->loadLayout();

        $collection = $this->wishlistHelper->getWishlistItemCollection();
        $collection->clear()->setPageSize(25)
            ->setInStockFilter(true)->setOrder('added_at');

        $items = [];
        foreach ($collection as $wishlistItem) {
            $items[] = $this->getWishItemData($wishlistItem);
        }
        return $items;
    }


    /**
     * @param $product
     * @param string $imageType
     * @return array
     */
    protected function getImage($product, $imageType = 'wishlist_sidebar_block')
    {
        /*Set variant product if it is configurable product.
        It will show variant product image in sidebar instead of configurable product image.*/
        $simpleOption = $product->getCustomOption('simple_product');
        if ($simpleOption !== null) {
            $optionProduct = $simpleOption->getProduct();
            $product = $optionProduct;
        }

        /** @var \Magento\Catalog\Helper\Image $helper */
        $helper = $this->imageHelperFactory->create()
            ->init($product, $imageType);

        $template = $helper->getFrame()
            ? 'Magento_Catalog/product/image'
            : 'Magento_Catalog/product/image_with_borders';

        try {
            $imagesize = $helper->getResizedImageInfo();
        } catch (NotLoadInfoImageException $exception) {
            $imagesize = [$helper->getWidth(), $helper->getHeight()];
        }

        $width = $helper->getFrame()
            ? $helper->getWidth()
            : $imagesize[0];

        $height = $helper->getFrame()
            ? $helper->getHeight()
            : $imagesize[1];

        return [
            'template' => $template,
            'src' => $helper->getUrl(),
            'width' => $width,
            'height' => $height,
            'alt' => $helper->getLabel(),
        ];
    }

    /**
     * @param $wishlistItem
     * @return array
     */
    public function getWishItemData($wishlistItem)
    {
        $product = $wishlistItem->getProduct();

        return [
            'image' => $this->getImage($product),
            'image_modal' => $this->getImage($product,'guestwishlist_block'),
            'product_url' => $this->wishlistHelper->getProductUrl($product),
            'product_name' => $product->getName(),
            'product_price' => $this->block->getProductPriceHtml(
                $product,
                'wishlist_configured_price',
                \Magento\Framework\Pricing\Render::ZONE_ITEM_LIST,
                ['item' => $wishlistItem]
            ),
            'product_is_saleable_and_visible' => $product->isSaleable() && $product->isVisibleInSiteVisibility(),
            'product_has_required_options' => $product->getTypeInstance()->hasRequiredOptions($product),
            'add_to_cart_params' => $this->wishlistHelper->getAddToCartParams($wishlistItem, true),
            'delete_item_params' => $this->wishlistHelper->getRemoveParams($wishlistItem, true),
        ] + $this->getSaleData($product) + $this->getSizeData($product);
    }

    /**
     * Sale flag data for the wishlist modal card (matches sale-flag.phtml).
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return array
     */
    protected function getSaleData($product)
    {
        $priceInfo = $product->getPriceInfo();
        $regularPrice = (float) $priceInfo
            ->getPrice(\Magento\Catalog\Pricing\Price\RegularPrice::PRICE_CODE)
            ->getAmount()->getValue();
        $finalPrice = (float) $priceInfo
            ->getPrice(\Magento\Catalog\Pricing\Price\FinalPrice::PRICE_CODE)
            ->getAmount()->getValue();

        $onSale = $regularPrice > 0 && $finalPrice < $regularPrice;

        return [
            'on_sale' => $onSale,
            'discount_percentage' => $onSale
                ? (int) round(100 * (1 - ($finalPrice / $regularPrice)), 0, PHP_ROUND_HALF_UP)
                : 0,
        ];
    }

    /**
     * Size data for the wishlist modal card (matches the product-card size line).
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return array
     */
    protected function getSizeData($product)
    {
        $isConfig = $product->getTypeId() === \Magento\ConfigurableProduct\Model\Product\Type\Configurable::TYPE_CODE;
        $isRound = (int) $product->getData('shape') === 20;
        $width = $product->getData('width');
        $length = $product->getData('length');

        $configSize = '';
        if ($width && $length) {
            $configSize = $isRound ? (string) $width : $width . ' x ' . $length;
        }

        return [
            'is_config' => $isConfig,
            'config_size' => $configSize,
            'is_round' => $isRound,
            'width' => $width ?: '',
            'length' => $length ?: '',
        ];
    }
}
