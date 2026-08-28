<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Quote;

use Exception;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\QuoteLogger;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\IsProductSalableForRequestedQtyInterface;
use Magento\InventorySalesApi\Model\StockByWebsiteIdResolverInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Build a quote the same way the admin "Create New Order" screen does:
 *  - super mode + ignoreOldQty so custom price is allowed (requested qty is still
 *    validated explicitly against MSI salable qty in addItems)
 *  - target store emulation so price, currency and tax resolve on the right scope
 */
class QuoteBuilder
{
    private const ADDRESS_FIELDS = [
        'firstname', 'lastname', 'company', 'street', 'city',
        'region', 'region_id', 'postcode', 'country_id', 'telephone', 'fax', 'email',
    ];

    public function __construct(
        protected readonly CartRepositoryInterface     $cartRepository,
        protected readonly QuoteFactory                $quoteFactory,
        protected readonly StoreManagerInterface       $storeManager,
        protected readonly ProductRepositoryInterface  $productRepository,
        protected readonly CustomerRepositoryInterface $customerRepository,
        protected readonly Emulation                   $emulation,
        protected readonly QuoteLogger                 $logger,
        protected readonly Config                      $config,
        protected readonly StockByWebsiteIdResolverInterface      $stockByWebsiteIdResolver,
        protected readonly IsProductSalableForRequestedQtyInterface $isProductSalableForRequestedQty,
        protected readonly GetProductSalableQtyInterface           $getProductSalableQty
    ) {
    }

    /**
     * @throws Exception
     */
    public function process(array $payload): array
    {
        if (empty($payload['items']) || !is_array($payload['items'])) {
            throw new Exception('items is required');
        }

        $updateId = (int)($payload['quote_id'] ?? 0);
        $isUpdate = $updateId > 0;

        // billing_address is optional at create time — it can be supplied later
        // or at submit. Shipping rates need a shipping address, so fetch shipping
        // methods only after an address is set.

        if ($isUpdate) {
            $quote = $this->cartRepository->get($updateId);
            if (!$quote->getIsActive()) {
                throw new Exception("Quote #$updateId is not active (already ordered?)");
            }
            $storeId = (int)$quote->getStoreId();
        } else {
            $storeId = (int)($payload['store_id'] ?? 0);
            if ($storeId <= 0) {
                throw new Exception('store_id is required');
            }
        }

        $store = $this->storeManager->getStore($storeId);

        // Resolve the MSI stock serving this store's website so we can validate
        // requested qty against real salable qty (super mode bypasses this otherwise).
        $stockId = (int)$this->stockByWebsiteIdResolver->execute((int)$store->getWebsiteId())->getStockId();

        // Emulate the target store so price/currency/tax collect on the right scope.
        $this->emulation->startEnvironmentEmulation($storeId, 'frontend', true);
        try {
            if (!$isUpdate) {
                $quote = $this->quoteFactory->create();
                $quote->setCurrency();
            }

            // Always bind the store so product salability resolves on the right
            // website — without it, addProduct on the update path can throw
            // "Product that you are trying to add is not available."
            $quote->setStore($store);
            $quote->setStoreId($storeId);

            // Admin-parity flags — bypass salable/min-max qty and allow custom price.
            $quote->setIsSuperMode(true);
            $quote->setIgnoreOldQty(true);

            // Customer: required on create; on update only reassign when supplied.
            if ($isUpdate) {
                if ((int)($payload['customer_id'] ?? 0) > 0 || trim((string)($payload['guest_email'] ?? '')) !== '') {
                    $this->assignCustomer($quote, $payload);
                }
            } else {
                $this->assignCustomer($quote, $payload);
            }

            // Items are full-replace: the payload is the desired state.
            if ($isUpdate) {
                $quote->removeAllItems();
            }
            $this->addItems($quote, $payload['items'], $storeId, $stockId, $this->config->isOrderCreateOversellAllowed());

            $this->applyAddresses($quote, $payload);

            // Coupon: only touch when the key is present (empty string removes it).
            if (array_key_exists('coupon_code', $payload)) {
                $quote->setCouponCode(trim((string)$payload['coupon_code']));
            }

            $quote->setInventoryProcessed(false);
            $quote->setTotalsCollectedFlag(false);
            $quote->collectTotals();

            // Shipping method (optional): set after the first collect so the full
            // rate list exists, then recollect to apply the shipping amount.
            $shippingMethod = trim((string)($payload['shipping_method'] ?? ''));
            if ($shippingMethod !== '' && !$quote->isVirtual()) {
                $shipping = $quote->getShippingAddress();
                if (!$shipping->getShippingRateByCode($shippingMethod)) {
                    throw new Exception("Shipping method '$shippingMethod' is not available for this quote");
                }
                $shipping->setShippingMethod($shippingMethod);
                $quote->setTotalsCollectedFlag(false);
                $quote->collectTotals();
            }

            $this->cartRepository->save($quote);
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }

        $quoteId = (int)$quote->getId();
        $tag = $isUpdate ? 'quote_update' : 'quote_create';
        $this->logger->writeDaily("[$tag] Quote #$quoteId — store $storeId, items " . count($payload['items']));

        $result = self::summarizeQuote($quote);
        $result['mode'] = $isUpdate ? 'updated' : 'created';
        return $result;
    }

    /**
     * @throws Exception
     */
    private function assignCustomer($quote, array $payload): void
    {
        $customerId = (int)($payload['customer_id'] ?? 0);
        if ($customerId > 0) {
            $customer = $this->customerRepository->getById($customerId);
            $quote->assignCustomer($customer);
            return;
        }

        $email = trim((string)($payload['guest_email'] ?? ''));
        if ($email === '') {
            throw new Exception('customer_id or guest_email is required');
        }
        $quote->setCustomerIsGuest(true);
        $quote->setCustomerEmail($email);
        $quote->setCustomerGroupId(\Magento\Customer\Model\Group::NOT_LOGGED_IN_ID);
    }

    /**
     * @throws Exception
     */
    private function addItems($quote, array $items, int $storeId, int $stockId, bool $allowOversell = false): void
    {
        foreach ($items as $row) {
            $sku = trim((string)($row['sku'] ?? ''));
            $qty = (float)($row['qty'] ?? 0);
            if ($sku === '' || $qty <= 0) {
                throw new Exception('Each item needs a sku and qty > 0');
            }

            try {
                $product = $this->productRepository->get($sku, false, $storeId);
            } catch (NoSuchEntityException) {
                throw new Exception("SKU '$sku' does not exist");
            }

            if ((int)$product->getStatus() !== Status::STATUS_ENABLED) {
                throw new Exception("SKU '$sku' is disabled and cannot be ordered");
            }
            if (!$product->isSalable()) {
                throw new Exception("SKU '$sku' is not available (out of stock or not sold in this store)");
            }

            // Requested-qty check: super mode bypasses addProduct's stock validation,
            // so enforce it explicitly against real MSI salable qty (respects
            // backorders / min-max / manage-stock config).
            // Skipped when admin-parity over-sell is enabled in config — then any
            // salable product adds regardless of available qty, like the admin
            // Create New Order screen.
            if (!$allowOversell) {
                $this->assertRequestedQtySalable($sku, $qty, $stockId);
            }

            try {
                $item = $quote->addProduct($product, $qty);
            } catch (LocalizedException $e) {
                // e.g. requested qty not available, min/max sale qty violations
                throw new Exception("SKU '$sku' cannot be added: " . $e->getMessage());
            }
            if (is_string($item)) {
                // addProduct returns an error string when it refuses the product
                throw new Exception("SKU '$sku' cannot be added: $item");
            }

            if (array_key_exists('custom_price', $row) && $row['custom_price'] !== null && $row['custom_price'] !== '') {
                $price = (float)$row['custom_price'];
                $item->setCustomPrice($price);
                $item->setOriginalCustomPrice($price);
                $item->getProduct()->setIsSuperMode(true);
            }

            // Per-line coupon opt-out: no_discount=true excludes this item from
            // cart-price-rule / coupon discounts while other lines still get them.
            if (array_key_exists('no_discount', $row) && $this->toBool($row['no_discount'])) {
                $item->setNoDiscount(1);
            }
        }
    }

    /**
     * Reject a line when the requested qty exceeds what is actually salable.
     * Uses MSI so backorders, min/max sale qty and manage-stock config are honored.
     *
     * @throws Exception
     */
    private function assertRequestedQtySalable(string $sku, float $qty, int $stockId): void
    {
        $result = $this->isProductSalableForRequestedQty->execute($sku, $stockId, $qty);
        if ($result->isSalable()) {
            return;
        }

        $reasons = [];
        foreach ($result->getErrors() as $error) {
            $reasons[] = $error->getMessage();
        }
        $detail = $reasons ? implode('; ', $reasons) : 'requested qty not available';

        // Best-effort available number for a clearer message (throws when stock
        // is unmanaged / product has no source — ignore and keep the base reason).
        try {
            $available = $this->getProductSalableQty->execute($sku, $stockId);
            $detail .= sprintf(' (requested %s, available %s)', $this->fmtQty($qty), $this->fmtQty($available));
        } catch (\Throwable) {
            // no numeric qty to report
        }

        throw new Exception("SKU '$sku' cannot be ordered: $detail");
    }

    private function fmtQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
    }

    /** Accept true/1/"1"/"true"/"yes" as true; everything else false. */
    private function toBool($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function applyAddresses($quote, array $payload): void
    {
        $hasBilling  = !empty($payload['billing_address']);
        $hasShipping = !empty($payload['shipping_address']);

        $billingData = $hasBilling ? $this->filterAddress($payload['billing_address']) : [];
        if ($billingData) {
            $quote->getBillingAddress()->addData($billingData);
        }

        if ($quote->isVirtual()) {
            return;
        }

        $shippingData = $hasShipping
            ? $this->filterAddress($payload['shipping_address'])
            : $billingData;

        if (!$shippingData) {
            return; // no address yet — caller sets it before fetching shipping methods / submit
        }

        $shipping = $quote->getShippingAddress();
        $shipping->addData($shippingData);
        $shipping->setSameAsBilling($hasShipping ? 0 : 1);
        $shipping->setCollectShippingRates(true);
    }

    private function filterAddress(array $data): array
    {
        $out = [];
        foreach (self::ADDRESS_FIELDS as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $key === 'street' && is_string($data[$key])
                    ? explode("\n", $data[$key])
                    : $data[$key];
            }
        }
        return $out;
    }

    /**
     * Full quote summary (items + totals) shared by create/update and coupon endpoints.
     */
    public static function summarizeQuote($quote): array
    {
        $items = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $rowInclTax = (float)$item->getRowTotalInclTax();
            $discount   = (float)$item->getDiscountAmount();

            $items[] = [
                'item_id'                           => (int)$item->getItemId(),
                'sku'                               => $item->getSku(),
                'name'                              => $item->getName(),
                'qty'                               => (float)$item->getQty(),
                'price'                             => (float)$item->getPrice(),          // unit, excl tax
                'price_incl_tax'                    => (float)$item->getPriceInclTax(),   // unit, incl tax (admin "Price")
                'discount_amount'                   => $discount,                         // admin "Discount"
                'row_total'                         => (float)$item->getRowTotal(),       // excl tax, before discount
                'row_total_incl_tax'                => $rowInclTax,                        // incl tax, before discount
                'row_total_after_discount_incl_tax' => round($rowInclTax - $discount, 4), // admin "Row Total"
            ];
        }

        return [
            'quote_id'    => (int)$quote->getId(),
            'store_id'    => (int)$quote->getStoreId(),
            'is_virtual'  => (bool)$quote->isVirtual(),
            'items'       => $items,
            'totals'      => self::buildTotals($quote),
        ];
    }

    /**
     * Order-summary totals matching the admin screen:
     * Subtotal, Shipping & Handling, Grand Total (Excl. Tax), Grand Total (Incl. Tax).
     */
    public static function buildTotals($quote): array
    {
        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();

        $subtotal        = (float)$address->getSubtotal();
        $subtotalInclTax = (float)$address->getSubtotalInclTax() ?: $subtotal;
        $shippingExclTax = (float)$address->getShippingAmount();
        $shippingInclTax = (float)$address->getShippingInclTax();
        $tax             = (float)$address->getTaxAmount();
        $discount        = (float)$address->getDiscountAmount(); // negative when a discount applies
        $grandInclTax    = (float)$address->getGrandTotal();
        $grandExclTax    = $grandInclTax - $tax;

        return [
            'coupon_code'                => (string)$quote->getCouponCode(),
            'subtotal'                   => $subtotal,
            'subtotal_incl_tax'          => $subtotalInclTax,
            'discount_amount'            => $discount,
            'shipping_handling'          => $shippingExclTax,
            'shipping_handling_incl_tax' => $shippingInclTax,
            'tax'                        => $tax,
            'grand_total_excl_tax'       => round($grandExclTax, 4),
            'grand_total_incl_tax'       => $grandInclTax,
        ];
    }
}
