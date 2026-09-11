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
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\CreditmemoCommentInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoItemInterfaceFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo as CreditmemoModel;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\Collection;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\CollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;

/**
 * The credit-memo half of the document export/import.
 *
 * Two things differ from the invoice beyond the column list:
 *
 *  - **`invoice_increment_id`.** A credit memo may point at the invoice it
 *    reverses (`sales_creditmemo.invoice_id`). The id itself is instance-local,
 *    so the CSV carries the invoice's increment id instead and the link is
 *    re-resolved on import. An empty cell means an order-level refund with no
 *    invoice behind it, which is legitimate.
 *  - **`state`** is the numeric credit-memo state: 1 open, 2 refunded,
 *    3 canceled ({@see CreditmemoModel::STATE_OPEN} and friends). The separate
 *    legacy `creditmemo_status` column is carried through untouched.
 */
class Creditmemo extends AbstractType
{
    /** Document-level columns, repeated on every item row of the same credit memo. */
    public const DOCUMENT_COLUMN_MAP = [
        // --- identity -------------------------------------------------------
        'increment_id' => 'document:increment_id',
        // How the credit memo finds its order on the target instance.
        'order_increment_id' => 'computed',
        'store_code' => 'computed',
        // Optional: the invoice this credit memo reverses, by increment id.
        'invoice_increment_id' => 'computed',
        'state' => 'document:state:num',
        'creditmemo_status' => 'document:creditmemo_status:num',
        'created_at' => 'document:created_at',
        'updated_at' => 'document:updated_at',
        'transaction_id' => 'document:transaction_id',

        // --- flags ----------------------------------------------------------
        'email_sent' => 'document:email_sent:num',
        'send_email' => 'document:send_email:num',
        'customer_note' => 'document:customer_note',
        'customer_note_notify' => 'document:customer_note_notify:num',
        'discount_description' => 'document:discount_description',

        // --- currency -------------------------------------------------------
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

        // --- adjustments (credit-memo only) ----------------------------------
        // "Adjustment Refund" and "Adjustment Fee" in the admin credit-memo form.
        'adjustment' => 'document:adjustment:num',
        'base_adjustment' => 'document:base_adjustment:num',
        'adjustment_positive' => 'document:adjustment_positive:num',
        'base_adjustment_positive' => 'document:base_adjustment_positive:num',
        'adjustment_negative' => 'document:adjustment_negative:num',
        'base_adjustment_negative' => 'document:base_adjustment_negative:num',

        'osc_gift_wrap_amount' => 'document:osc_gift_wrap_amount:num',
        'base_osc_gift_wrap_amount' => 'document:base_osc_gift_wrap_amount:num',

        // --- 1-to-many collection packed as JSON in a single cell -----------
        'document_comments' => 'computed',
    ];

    /** @var array<int, string> invoice entity id => increment id (per-run cache) */
    private array $invoiceIncrementIds = [];

    /**
     * `Magento\Sales\Model\Order\CreditmemoFactory` is a real service class
     * (`createByOrder()`), NOT a generated model factory — injecting it here
     * would hand back the wrong object. The Api\Data interface factory is the
     * one that builds a bare credit-memo model.
     */
    public function __construct(
        private readonly CreditmemoInterfaceFactory $creditmemoFactory,
        private readonly CreditmemoItemInterfaceFactory $itemFactory,
        private readonly CreditmemoCommentInterfaceFactory $commentFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly InvoiceCollectionFactory $invoiceCollectionFactory,
        private readonly CreditmemoRepositoryInterface $creditmemoRepository,
    ) {
    }

    public function getCode(): string
    {
        return 'creditmemo';
    }

    public function getLabel(): string
    {
        return (string)__('Credit Memo');
    }

    public function getFilePrefix(): string
    {
        return 'creditmemos';
    }

    public function getDocumentColumnMap(): array
    {
        return self::DOCUMENT_COLUMN_MAP;
    }

    /** @inheritDoc */
    public function getStateOptions(): array
    {
        return [
            ['value' => CreditmemoModel::STATE_OPEN, 'label' => (string)__('Open')],
            ['value' => CreditmemoModel::STATE_REFUNDED, 'label' => (string)__('Refunded')],
            ['value' => CreditmemoModel::STATE_CANCELED, 'label' => (string)__('Canceled')],
        ];
    }

    public function createCollection(): Collection
    {
        return $this->collectionFactory->create();
    }

    public function createDocument(): AbstractModel
    {
        /** @var CreditmemoModel $creditmemo */
        $creditmemo = $this->creditmemoFactory->create();
        return $creditmemo;
    }

    public function createItem(): AbstractModel
    {
        /** @var \Magento\Sales\Model\Order\Creditmemo\Item $item */
        $item = $this->itemFactory->create();
        return $item;
    }

    /** @inheritDoc */
    public function getItems(AbstractModel $document): array
    {
        /** @var CreditmemoModel $document */
        return $this->normalizeItems($document->getItems());
    }

    /** @inheritDoc */
    public function addItem(AbstractModel $document, AbstractModel $item): void
    {
        /** @var CreditmemoModel $document */
        /** @var \Magento\Sales\Model\Order\Creditmemo\Item $item */
        $document->addItem($item);
    }

    /** @inheritDoc */
    public function addComment(AbstractModel $document, array $entry): void
    {
        /** @var CreditmemoModel $document */
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

    /** @inheritDoc */
    public function attachToOrder(AbstractModel $document, Order $order): void
    {
        /** @var CreditmemoModel $document */
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
        /** @var CreditmemoModel $document */
        $document = $collection->getFirstItem();
        return $document->getId() ? $document : null;
    }

    /** @inheritDoc */
    public function save(AbstractModel $document): void
    {
        /** @var CreditmemoModel $document */
        $this->creditmemoRepository->save($document);
    }

    /**
     * Export `invoice_increment_id` from the instance-local `invoice_id`.
     *
     * @inheritDoc
     */
    public function exportExtraColumns(AbstractModel $document): array
    {
        return ['invoice_increment_id' => $this->invoiceIncrementId((int)$document->getData('invoice_id'))];
    }

    /**
     * Re-resolve `invoice_increment_id` to a local `invoice_id`.
     *
     * A named invoice that does not exist here leaves the link empty rather than
     * failing the row: the credit memo's own amounts are complete without it,
     * and the alternative — pointing at a random invoice id — would be worse.
     *
     * @inheritDoc
     */
    public function applyExtraColumns(AbstractModel $document, array $data, Order $order): void
    {
        $invoiceIncrementId = (string)($data['invoice_increment_id'] ?? '');
        if ($invoiceIncrementId === '') {
            return;
        }

        $collection = $this->invoiceCollectionFactory->create();
        $collection->addFieldToFilter('increment_id', $invoiceIncrementId)
            ->addFieldToFilter('order_id', (int)$order->getId())
            ->setPageSize(1);
        $invoice = $collection->getFirstItem();
        if ($invoice->getId()) {
            $document->setData('invoice_id', (int)$invoice->getId());
        }
    }

    /**
     * One invoice's increment id, '' when the id is unset or the invoice is gone.
     */
    private function invoiceIncrementId(int $invoiceId): string
    {
        if ($invoiceId <= 0) {
            return '';
        }
        if (!array_key_exists($invoiceId, $this->invoiceIncrementIds)) {
            $collection = $this->invoiceCollectionFactory->create();
            $collection->addFieldToFilter('entity_id', $invoiceId)->setPageSize(1);
            $this->invoiceIncrementIds[$invoiceId] = (string)$collection->getFirstItem()->getIncrementId();
        }
        return $this->invoiceIncrementIds[$invoiceId];
    }
}
