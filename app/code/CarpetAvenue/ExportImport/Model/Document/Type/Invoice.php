<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Order
 * @package   CarpetAvenue_ExportImport
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\ExportImport\Model\Document\Type;

use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Api\Data\InvoiceCommentInterfaceFactory;
use Magento\Sales\Api\Data\InvoiceInterfaceFactory;
use Magento\Sales\Api\Data\InvoiceItemInterfaceFactory;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice as InvoiceModel;
use Magento\Sales\Model\ResourceModel\Order\Invoice\Collection;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory;

/**
 * The invoice half of the document export/import.
 *
 * Column map notes:
 *
 *  - `state` is Magento's numeric invoice state: 1 open, 2 paid, 3 canceled
 *    ({@see InvoiceModel::STATE_OPEN} and friends). It is exported and imported
 *    as the number, not a label, so it cannot drift with translations.
 *  - `base_total_refunded` is how much of THIS invoice has been credited — a
 *    figure Magento derives from the credit memos pointing at the invoice. It is
 *    exported and imported like any other column, so a file from a source
 *    instance carries the right value on its own; when the totals synchroniser
 *    runs it recomputes the column from the credit memos actually present, which
 *    also covers credit memos imported in a later run.
 */
class Invoice extends AbstractType
{
    /** Document-level columns, repeated on every item row of the same invoice. */
    public const DOCUMENT_COLUMN_MAP = [
        // --- identity -------------------------------------------------------
        'increment_id' => 'document:increment_id',
        // How the invoice finds its order on the target instance.
        'order_increment_id' => 'computed',
        'store_code' => 'computed',
        'state' => 'document:state:num',
        'created_at' => 'document:created_at',
        'updated_at' => 'document:updated_at',
        'transaction_id' => 'document:transaction_id',

        // --- flags ----------------------------------------------------------
        'is_used_for_refund' => 'document:is_used_for_refund:num',
        'can_void_flag' => 'document:can_void_flag:num',
        'email_sent' => 'document:email_sent:num',
        'send_email' => 'document:send_email:num',
        'customer_note' => 'document:customer_note',
        'customer_note_notify' => 'document:customer_note_notify:num',
        'discount_description' => 'document:discount_description',

        // --- currency -------------------------------------------------------
        // Without the rates the order-currency and base-currency amounts on the
        // document cannot be reconciled — same reasoning as the order CSV.
        'order_currency_code' => 'document:order_currency_code',
        'base_currency_code' => 'document:base_currency_code',
        'global_currency_code' => 'document:global_currency_code',
        'store_currency_code' => 'document:store_currency_code',
        'base_to_order_rate' => 'document:base_to_order_rate:num',
        'base_to_global_rate' => 'document:base_to_global_rate:num',
        'store_to_base_rate' => 'document:store_to_base_rate:num',
        'store_to_order_rate' => 'document:store_to_order_rate:num',
        'store_to_eur_rate' => 'document:store_to_eur_rate:num',

        // --- totals ---------------------------------------------------------
        'grand_total' => 'document:grand_total:num',
        'base_grand_total' => 'document:base_grand_total:num',
        'subtotal' => 'document:subtotal:num',
        'base_subtotal' => 'document:base_subtotal:num',
        'subtotal_incl_tax' => 'document:subtotal_incl_tax:num',
        'base_subtotal_incl_tax' => 'document:base_subtotal_incl_tax:num',
        'tax_amount' => 'document:tax_amount:num',
        'base_tax_amount' => 'document:base_tax_amount:num',
        'shipping_amount' => 'document:shipping_amount:num',
        'base_shipping_amount' => 'document:base_shipping_amount:num',
        'shipping_incl_tax' => 'document:shipping_incl_tax:num',
        'base_shipping_incl_tax' => 'document:base_shipping_incl_tax:num',
        'shipping_tax_amount' => 'document:shipping_tax_amount:num',
        'base_shipping_tax_amount' => 'document:base_shipping_tax_amount:num',
        'discount_amount' => 'document:discount_amount:num',
        'base_discount_amount' => 'document:base_discount_amount:num',
        'discount_tax_compensation_amount' => 'document:discount_tax_compensation_amount:num',
        'base_discount_tax_compensation_amount' => 'document:base_discount_tax_compensation_amount:num',
        'shipping_discount_tax_compensation_amount' => 'document:shipping_discount_tax_compensation_amount:num',
        'base_shipping_discount_tax_compensation_amnt' => 'document:base_shipping_discount_tax_compensation_amnt:num',
        'total_qty' => 'document:total_qty:num',
        'osc_gift_wrap_amount' => 'document:osc_gift_wrap_amount:num',
        'base_osc_gift_wrap_amount' => 'document:base_osc_gift_wrap_amount:num',
        // How much of this invoice has been credited. Imported verbatim, then
        // overwritten by the totals synchroniser when it runs — see the class
        // docblock.
        'base_total_refunded' => 'document:base_total_refunded:num',

        // --- 1-to-many collection packed as JSON in a single cell -----------
        'document_comments' => 'computed',
    ];

    /**
     * The Api\Data interface factories are used rather than the model ones on
     * purpose: `Magento\Sales\Model\Order\CreditmemoFactory` is a real service
     * class, not a generated model factory, so the credit-memo type cannot use
     * that naming — and the two types stay symmetrical this way.
     */
    public function __construct(
        private readonly InvoiceInterfaceFactory $invoiceFactory,
        private readonly InvoiceItemInterfaceFactory $itemFactory,
        private readonly InvoiceCommentInterfaceFactory $commentFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly InvoiceRepositoryInterface $invoiceRepository,
    ) {
    }

    public function getCode(): string
    {
        return 'invoice';
    }

    public function getLabel(): string
    {
        return (string)__('Invoice');
    }

    public function getFilePrefix(): string
    {
        return 'invoices';
    }

    public function getDocumentColumnMap(): array
    {
        return self::DOCUMENT_COLUMN_MAP;
    }

    /** @inheritDoc */
    public function getStateOptions(): array
    {
        return [
            ['value' => InvoiceModel::STATE_OPEN, 'label' => (string)__('Pending')],
            ['value' => InvoiceModel::STATE_PAID, 'label' => (string)__('Paid')],
            ['value' => InvoiceModel::STATE_CANCELED, 'label' => (string)__('Canceled')],
        ];
    }

    public function createCollection(): Collection
    {
        return $this->collectionFactory->create();
    }

    public function createDocument(): AbstractModel
    {
        /** @var InvoiceModel $invoice */
        $invoice = $this->invoiceFactory->create();
        return $invoice;
    }

    public function createItem(): AbstractModel
    {
        /** @var \Magento\Sales\Model\Order\Invoice\Item $item */
        $item = $this->itemFactory->create();
        return $item;
    }

    /** @inheritDoc */
    public function getItems(AbstractModel $document): array
    {
        /** @var InvoiceModel $document */
        return $this->normalizeItems($document->getItems());
    }

    /** @inheritDoc */
    public function addItem(AbstractModel $document, AbstractModel $item): void
    {
        /** @var InvoiceModel $document */
        /** @var \Magento\Sales\Model\Order\Invoice\Item $item */
        $document->addItem($item);
    }

    /** @inheritDoc */
    public function addComment(AbstractModel $document, array $entry): void
    {
        /** @var InvoiceModel $document */
        $comment = $this->commentFactory->create();
        foreach (self::COMMENT_FIELDS as $field) {
            if (array_key_exists($field, $entry)) {
                $comment->setData($field, $entry[$field]);
            }
        }
        // Passing the model (not a string) keeps the exported created_at,
        // notify flag and visibility instead of letting Magento default them.
        $document->addComment($comment);
    }

    /**
     * `order_id` and `billing_address_id` are set here rather than left to
     * `Magento\Sales\Model\ResourceModel\Order\Invoice::_beforeSave()`, which
     * fills them only when `order_id` is empty and does
     * `$object->getOrder()->getBillingAddress()->getId()` with **no null guard** —
     * so an order without a billing address fatals there. (The credit-memo
     * resource guards the same call; the invoice one does not.) Setting
     * `order_id` up front skips that branch entirely.
     *
     * @inheritDoc
     */
    public function attachToOrder(AbstractModel $document, Order $order): void
    {
        /** @var InvoiceModel $document */
        $document->setOrder($order);
        $document->setOrderId((int)$order->getId());
        $document->setStoreId((int)$order->getStoreId());

        $billingAddress = $order->getBillingAddress();
        if ($billingAddress) {
            $document->setBillingAddressId((int)$billingAddress->getId());
        }
    }

    public function loadByIncrementId(string $incrementId): ?AbstractModel
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('increment_id', $incrementId)->setPageSize(1);
        /** @var InvoiceModel $document */
        $document = $collection->getFirstItem();
        return $document->getId() ? $document : null;
    }

    /** @inheritDoc */
    public function save(AbstractModel $document): void
    {
        /** @var InvoiceModel $document */
        $this->invoiceRepository->save($document);
    }
}
