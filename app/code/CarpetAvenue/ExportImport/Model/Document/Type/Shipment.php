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
use Magento\Sales\Api\Data\ShipmentCommentInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentItemInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentTrackInterfaceFactory;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment as ShipmentModel;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Collection;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory;

/**
 * The shipment half of the document export/import.
 *
 * A shipment is the same shape as an invoice — one row per document, an item
 * table and a comment table hanging off one order — so it reuses Schema,
 * Exporter, Validator and Importer unchanged. Four things are genuinely
 * different:
 *
 *  - **No money.** `sales_shipment` carries no amount, no currency code and no
 *    conversion rate, so {@see hasCurrencyColumns()} is false: the importer
 *    writes no currency fallback and the validator raises no cross-currency
 *    warning. The item table has `price` and `row_total`, which are a copy of
 *    the order item's figures at ship time and are exported as-is.
 *  - **No state.** {@see getStateField()} is null, so the export filter offers
 *    no state select and the update path has no state to change.
 *    `shipment_status` is carried as an ordinary column — it is a legacy column
 *    Magento never writes, not a lifecycle state.
 *  - **Tracks.** `sales_shipment_track` is a second 1-to-many child, packed into
 *    the `document_tracks` JSON cell the way comments are packed into
 *    `document_comments`, and additionally flattened into the export-only
 *    `track_numbers` / `track_carrier_codes` / `track_titles` columns so a
 *    tracking number is readable without opening the JSON. They are replayed
 *    after the first save ({@see applyPostSaveColumns()}), because
 *    `Shipment::addTrack()` stamps the track's NOT NULL `parent_id` from the
 *    shipment at add time.
 *  - **A slimmer item map.** `sales_shipment_item` has 8 business columns, not
 *    the ~30 of the money-document item tables, so this class declares its own
 *    `ITEM_COLUMN_MAP` rather than inheriting {@see AbstractType}'s.
 *
 * ## Scope: which DB columns are here and which are not
 *
 * Every business column of `sales_shipment`, `sales_shipment_item` and
 * `sales_shipment_track` is present. Absent:
 *
 *  1. **Internal ids and relations** — `entity_id`, `parent_id`, `store_id`,
 *     `order_id`, `customer_id`, `product_id`, `order_item_id`,
 *     `billing_address_id`, `shipping_address_id`. All are instance-local and
 *     are re-derived from the order and the item SKU on import, exactly as for
 *     the money documents.
 *  2. **`shipping_label`** — a `mediumblob` holding the carrier's raw label
 *     (PDF/PNG/ZPL bytes) as returned by the carrier API. Binary in a CSV cell
 *     would need base64 and would multiply the file size by the weight of every
 *     label; a label is re-printable from the carrier and is not a record of the
 *     shipment. Not exported, not imported, and an existing one is never
 *     touched.
 */
class Shipment extends AbstractType
{
    /** Document-level columns, repeated on every item row of the same shipment. */
    public const DOCUMENT_COLUMN_MAP = [
        // --- identity -------------------------------------------------------
        'increment_id' => 'document:increment_id',
        // How the shipment finds its order on the target instance.
        'order_increment_id' => 'computed',
        'store_code' => 'computed',
        'created_at' => 'document:created_at',
        'updated_at' => 'document:updated_at',
        // Legacy column: nothing in Magento writes it, carried so the row
        // round-trips on installs where an extension does.
        'shipment_status' => 'document:shipment_status:num',

        // --- flags ----------------------------------------------------------
        'email_sent' => 'document:email_sent:num',
        'send_email' => 'document:send_email:num',
        'customer_note' => 'document:customer_note',
        'customer_note_notify' => 'document:customer_note_notify:num',

        // --- quantities -----------------------------------------------------
        // The only aggregates on the table: shipped units and total weight.
        'total_qty' => 'document:total_qty:num',
        'total_weight' => 'document:total_weight:num',
        // The admin's packaging popup: an ARRAY on the model, JSON in the column.
        // `computed`, not a plain mapped column, because the resource serialises
        // it on save — see exportExtraColumns() / applyExtraColumns().
        'packages' => 'computed',

        // --- 1-to-many collections packed as JSON in a single cell ----------
        'document_comments' => 'computed',
        'document_tracks' => 'computed',

        // --- tracking, flattened for reading ---------------------------------
        // The same tracks as `document_tracks`, as plain comma-separated lists
        // so a tracking number is visible in a spreadsheet without opening the
        // JSON. EXPORT ONLY — see getExportOnlyColumns().
        'track_numbers' => 'computed',
        'track_carrier_codes' => 'computed',
        'track_titles' => 'computed',
    ];

    /**
     * Item-level columns — one distinct set per CSV row.
     *
     * `sales_shipment_item` holds no tax, discount or FPT columns: a shipment
     * moves goods, it does not price them. `price` and `row_total` are copies of
     * the order item's figures taken when the shipment was created.
     */
    public const ITEM_COLUMN_MAP = [
        'item_sku' => 'item:sku',
        'item_name' => 'item:name',
        'item_description' => 'item:description',
        'item_additional_data' => 'item:additional_data',
        'item_qty' => 'item:qty:num',
        'item_price' => 'item:price:num',
        'item_row_total' => 'item:row_total:num',
        'item_weight' => 'item:weight:num',
    ];

    /** Both JSON-packed children of a shipment. */
    public const JSON_COLUMNS = [
        'document_comments',
        'document_tracks',
    ];

    /**
     * Numeric columns that keep the "empty cell means 0" behaviour.
     *
     * `total_weight`, `item_weight`, `item_price` and `item_row_total` are all
     * nullable and are genuinely NULL on plenty of real shipments (a virtual
     * item has no weight, a shipment created through the API often has no
     * prices), so an empty cell leaves them NULL rather than writing 0.0000.
     */
    public const ZERO_DEFAULT_COLUMNS = [
        'total_qty',
        'item_qty',
    ];

    /**
     * The flattened tracking columns: written on export, ignored on import.
     *
     * `document_tracks` is the authority — it carries every field of every track
     * and is what the importer replays. These three are a convenience for
     * reading and for pasting into a carrier's site, and re-importing a file
     * never reads them, so editing one changes nothing. Edit `document_tracks`.
     */
    public const EXPORT_ONLY_COLUMNS = [
        'track_numbers',
        'track_carrier_codes',
        'track_titles',
    ];

    /** The track fields carried in the `document_tracks` JSON cell. */
    private const TRACK_FIELDS = [
        'track_number',
        'title',
        'carrier_code',
        'description',
        'qty',
        'weight',
        'created_at',
    ];

    public function __construct(
        private readonly ShipmentInterfaceFactory $shipmentFactory,
        private readonly ShipmentItemInterfaceFactory $itemFactory,
        private readonly ShipmentCommentInterfaceFactory $commentFactory,
        private readonly ShipmentTrackInterfaceFactory $trackFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
    ) {
    }

    public function getCode(): string
    {
        return 'shipment';
    }

    public function getLabel(): string
    {
        return (string)__('Shipment');
    }

    public function getFilePrefix(): string
    {
        return 'shipments';
    }

    public function getDocumentColumnMap(): array
    {
        return self::DOCUMENT_COLUMN_MAP;
    }

    /**
     * `sales_shipment` has no state column — see the class docblock.
     *
     * @inheritDoc
     */
    public function getStateField(): ?string
    {
        return null;
    }

    /**
     * A shipment holds no amount, so there is no currency to preserve.
     *
     * @inheritDoc
     */
    public function hasCurrencyColumns(): bool
    {
        return false;
    }

    public function createCollection(): Collection
    {
        return $this->collectionFactory->create();
    }

    public function createDocument(): AbstractModel
    {
        /** @var ShipmentModel $shipment */
        $shipment = $this->shipmentFactory->create();
        return $shipment;
    }

    public function createItem(): AbstractModel
    {
        /** @var \Magento\Sales\Model\Order\Shipment\Item $item */
        $item = $this->itemFactory->create();
        return $item;
    }

    /** @inheritDoc */
    public function getItems(AbstractModel $document): array
    {
        /** @var ShipmentModel $document */
        return $this->normalizeItems($document->getItems());
    }

    /** @inheritDoc */
    public function addItem(AbstractModel $document, AbstractModel $item): void
    {
        /** @var ShipmentModel $document */
        /** @var \Magento\Sales\Model\Order\Shipment\Item $item */
        $document->addItem($item);
    }

    /** @inheritDoc */
    public function addComment(AbstractModel $document, array $entry): void
    {
        /** @var ShipmentModel $document */
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
     * A shipment's addresses come from the order, and so does the customer: all
     * three columns hold instance-local ids that the CSV cannot carry.
     *
     * @inheritDoc
     */
    public function attachToOrder(AbstractModel $document, Order $order): void
    {
        /** @var ShipmentModel $document */
        $document->setOrder($order);
        $document->setOrderId((int)$order->getId());
        $document->setStoreId((int)$order->getStoreId());
        $document->setCustomerId($order->getCustomerId() ? (int)$order->getCustomerId() : null);

        $shippingAddress = $order->getShippingAddress();
        if ($shippingAddress) {
            $document->setShippingAddressId((int)$shippingAddress->getId());
        }
        $billingAddress = $order->getBillingAddress();
        if ($billingAddress) {
            $document->setBillingAddressId((int)$billingAddress->getId());
        }
    }

    public function loadByIncrementId(string $incrementId): ?AbstractModel
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('increment_id', $incrementId)->setPageSize(1);
        /** @var ShipmentModel $document */
        $document = $collection->getFirstItem();
        return $document->getId() ? $document : null;
    }

    /** @inheritDoc */
    public function save(AbstractModel $document): void
    {
        /** @var ShipmentModel $document */
        $this->shipmentRepository->save($document);
    }

    /** @inheritDoc */
    public function getExportOnlyColumns(): array
    {
        return self::EXPORT_ONLY_COLUMNS;
    }

    /**
     * Pack the shipment's tracking rows into `document_tracks`, and flatten the
     * numbers, carrier codes and titles into three readable columns beside it.
     *
     * Ordered oldest first, like the comments, because the importer replays the
     * entries in array order — and so the nth `track_numbers` entry lines up
     * with the nth `track_carrier_codes` entry.
     *
     * A shipment usually has one track, in which case all three flat columns
     * hold a single value. Multiple tracks are comma-separated, which is why a
     * comma inside a carrier's own title would be ambiguous there and is not in
     * `document_tracks`.
     *
     * @inheritDoc
     */
    public function exportExtraColumns(AbstractModel $document): array
    {
        /** @var ShipmentModel $document */
        $tracks = $document->getTracksCollection()->getItems();
        usort(
            $tracks,
            static fn ($a, $b) => [$a->getCreatedAt(), (int)$a->getEntityId()]
                <=> [$b->getCreatedAt(), (int)$b->getEntityId()]
        );

        $entries = [];
        $numbers = [];
        $carriers = [];
        $titles = [];
        foreach ($tracks as $track) {
            $entry = [];
            foreach (self::TRACK_FIELDS as $field) {
                $value = $track->getData($field);
                if ($value !== null) {
                    $entry[$field] = $value;
                }
            }
            $entries[] = $entry;

            $numbers[] = (string)$track->getData('track_number');
            $carriers[] = (string)$track->getData('carrier_code');
            $titles[] = (string)$track->getData('title');
        }

        return [
            'document_tracks' => $entries === [] ? '' : (string)json_encode($entries),
            'track_numbers' => implode(',', $numbers),
            'track_carrier_codes' => implode(',', $carriers),
            'track_titles' => implode(',', $titles),
            'packages' => $this->encodePackages($document->getData('packages')),
        ];
    }

    /**
     * Recreate `packages` as the ARRAY the model expects.
     *
     * `Magento\Sales\Model\ResourceModel\Order\Shipment` declares
     * `$_serializableFields = ['packages' => [[], []]]`, so the resource
     * json-encodes the value on save and json-decodes it on load. Handing it the
     * exported JSON *string* stores a json-encoded string instead of the package
     * structure: the column then reads back as a string, and the admin shipment
     * view dies in `Magento_Shipping::order/packaging/packed.phtml`, which does
     * `foreach ($block->getPackages() ...)`. Decoding here is what keeps the
     * round-trip honest — the same reason the order importer decodes
     * `product_options`.
     *
     * @inheritDoc
     */
    public function applyExtraColumns(AbstractModel $document, array $data, Order $order): void
    {
        $json = (string)($data['packages'] ?? '');
        if ($json === '') {
            return;
        }

        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $document->setData('packages', $decoded);
        }
    }

    /**
     * `packages` as a CSV cell: JSON text, whatever the model handed over.
     *
     * A shipment loaded through the resource has the decoded array; one built in
     * memory may still hold the raw JSON string. Both export as the same text,
     * so a file is identical either way.
     */
    private function encodePackages(mixed $packages): string
    {
        if ($packages === null || $packages === '') {
            return '';
        }
        return is_array($packages) ? (string)json_encode($packages) : (string)$packages;
    }

    /**
     * Recreate the shipment's tracking rows from `document_tracks`.
     *
     * After the first save, not before: `Shipment::addTrack()` copies the
     * shipment's id into the track's NOT NULL `parent_id` at add time, so a
     * track added to an unsaved shipment would try to insert a null parent.
     *
     * Only on the create path. A re-import must not replay the tracks of a
     * shipment that already has them — there is no key to match an exported
     * track against a stored one (a carrier can and does reuse a number across
     * shipments), so matching would either duplicate the thread or drop a real
     * second track. The importer only calls this for a document it just created.
     *
     * @inheritDoc
     */
    public function applyPostSaveColumns(AbstractModel $document, array $data, Order $order): bool
    {
        /** @var ShipmentModel $document */
        $json = (string)($data['document_tracks'] ?? '');
        if ($json === '') {
            return false;
        }

        $entries = json_decode($json, true);
        if (!is_array($entries)) {
            return false;
        }

        $added = false;
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $track = $this->trackFactory->create();
            foreach (self::TRACK_FIELDS as $field) {
                if (array_key_exists($field, $entry)) {
                    $track->setData($field, $entry[$field]);
                }
            }
            $document->addTrack($track);
            $added = true;
        }

        return $added;
    }
}
