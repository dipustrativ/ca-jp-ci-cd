<?php

namespace CarpetAvenue\Productify\Helper;


use Magento\Framework\App\Helper\AbstractHelper;
use Magento\InventoryCatalog\Model\GetStockIdForCurrentWebsite;
use Magento\InventorySalesApi\Api\AreProductsSalableInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesAdminUi\Model\ResourceModel\GetAssignedStockIdsBySku;
use Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface;
use Magento\Checkout\Model\Session;

/**
 * Class QtyHelper
 * @package Evalent\CatalogMods\Helper\Product
 */
class QtyHelper extends AbstractHelper
{
    /**
     * @var \Magento\InventorySalesApi\Api\AreProductsSalableInterface
     */
    protected AreProductsSalableInterface $areProductsSalable;

    /**
     * @var \Magento\InventoryCatalog\Model\GetStockIdForCurrentWebsite
     */
    protected GetStockIdForCurrentWebsite $getStockIdForCurrentWebsite;

    /**
     * @var GetProductSalableQtyInterface
     */
    protected GetProductSalableQtyInterface $getProductSalableQty;

    /**
     * @var GetAssignedStockIdsBySku
     */
    protected GetAssignedStockIdsBySku $getAssignedStockIdsBySku;

    /**
     * @var GetStockItemConfigurationInterface
     */
    protected GetStockItemConfigurationInterface $getStockItemConfiguration;

    private $session;

    /**
     * isManageStock() by SKU, request-scoped. The listing calls it once per tile
     * and each miss is a full MSI stock-item-configuration lookup.
     *
     * @var array<string, bool>
     */
    private array $manageStockBySku = [];

    /**
     * @var int|null
     */
    private ?int $currentStockId = null;

    /**
     * QtyHelper constructor.
     *
     * @param \Magento\InventorySalesApi\Api\AreProductsSalableInterface                  $areProductsSalable
     * @param \Magento\InventoryCatalog\Model\GetStockIdForCurrentWebsite                 $getStockIdForCurrentWebsite
     * @param \Magento\InventorySalesApi\Api\GetProductSalableQtyInterface                $getProductSalableQty
     * @param \Magento\InventorySalesAdminUi\Model\ResourceModel\GetAssignedStockIdsBySku $getAssignedStockIdsBySku
     * @param \Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface   $getStockItemConfiguration
     */
    public function __construct(
        AreProductsSalableInterface $areProductsSalable,
        GetStockIdForCurrentWebsite $getStockIdForCurrentWebsite,
        GetProductSalableQtyInterface $getProductSalableQty,
        GetAssignedStockIdsBySku $getAssignedStockIdsBySku,
        GetStockItemConfigurationInterface $getStockItemConfiguration,
        Session $session
    )
    {
        $this->getProductSalableQty = $getProductSalableQty;
        $this->getAssignedStockIdsBySku = $getAssignedStockIdsBySku;
        $this->getStockItemConfiguration = $getStockItemConfiguration;
        $this->areProductsSalable = $areProductsSalable;
        $this->getStockIdForCurrentWebsite = $getStockIdForCurrentWebsite;
        $this->session = $session;
    }

    /**
     * @param $product
     * @return bool
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\InventoryConfigurationApi\Exception\SkuIsNotAssignedToStockException
     */
    public function isSalable($product): bool
    {
        $stockIds = $this->getAssignedStockIdsBySku->execute($product->getSku());
        $stockId = $this->getStockIdForCurrentWebsite->execute();
        if (count($stockIds) && in_array($stockId, $stockIds)) {

            $stockItemConfiguration = $this->getStockItemConfiguration->execute($product->getSku(), $stockId);
            $isManageStock = $stockItemConfiguration->isManageStock();
            return boolval($isManageStock ? $this->getProductSalableQty->execute($product->getSku(), $stockId) : null);
        }

        return true;
    }

    /**
     * Whether the product actually manages stock for the current website's stock.
     *
     * Used to decide if a "Sold" caption is meaningful: a product with Manage
     * Stock = No is never really "sold out", so the caption must not show for it.
     * Defaults to true on any lookup failure so behaviour is unchanged when the
     * SKU isn't assigned to a stock, etc.
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface|\Magento\Catalog\Model\Product $product
     * @return bool
     */
    public function isManageStock($product): bool
    {
        $sku = (string) $product->getSku();
        if (array_key_exists($sku, $this->manageStockBySku)) {
            return $this->manageStockBySku[$sku];
        }

        try {
            $stockItemConfiguration = $this->getStockItemConfiguration->execute(
                $sku,
                $this->getCurrentStockId()
            );

            return $this->manageStockBySku[$sku] = (bool) $stockItemConfiguration->isManageStock();
        } catch (\Exception $e) {
            return $this->manageStockBySku[$sku] = true;
        }
    }

    /**
     * Stock id for the current website, resolved once per request.
     *
     * @return int
     */
    private function getCurrentStockId(): int
    {
        return $this->currentStockId ??= (int) $this->getStockIdForCurrentWebsite->execute();
    }

    public function getProductIsInCart($product): bool
    {
        $cart = $this->session->getQuote();
        $result = $cart->getAllVisibleItems();
        $itemsIds = array();
        $inCart = false;

        foreach ($result as $cartItem) {
            $inCart = $cartItem->getProduct()->getSku() == $product->getSku();
        }

        return $inCart;
    }
}
