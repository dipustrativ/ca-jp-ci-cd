<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * One reservation line exchanged with IXPF.
 *
 * Rows are never updated in place except to record the outcome of their own
 * delivery — an order accumulates one batch at placement and one per lifecycle
 * event, so the table doubles as the audit trail.
 */
class IxpfReservation extends AbstractModel
{
    public const TABLE = 'ixpf_reservations';
    public const ENTITY = 'entity_id';

    /** Which way the line travelled. */
    public const DIRECTION_OUTBOUND = 'outbound';
    public const DIRECTION_INBOUND = 'inbound';

    /** Sign of the quantity, denormalised so the grid can filter on it. */
    public const TYPE_DECREMENT = 'decrement';
    public const TYPE_INCREMENT = 'increment';

    /**
     * Business lifecycle stage. This is what goes out as `status` in the payload.
     */
    public const RESERVATION_PENDING = 'pending';
    public const RESERVATION_COMPLETED = 'completed';
    public const RESERVATION_CANCELLED = 'cancelled';
    public const RESERVATION_REFUNDED = 'refunded';

    /**
     * Delivery state, independent of the lifecycle stage above.
     *
     * Outbound: pending -> processing -> success | failed
     * Inbound:  pending -> applied | failed
     *
     * `failed` means we tried and it did not work, so the retry cron will try
     * again. `skipped` means nothing was sent and nothing ever will be — there
     * was no reservation to talk about. Keeping them apart is what stops the
     * cron POSTing an empty products array once a minute forever.
     */
    public const REQUEST_PENDING = 'pending';
    public const REQUEST_PROCESSING = 'processing';
    public const REQUEST_SUCCESS = 'success';
    public const REQUEST_FAILED = 'failed';
    public const REQUEST_APPLIED = 'applied';
    public const REQUEST_SKIPPED = 'skipped';

    public function _construct()
    {
        $this->_init(\IXPF\Syncer\Model\ResourceModel\IxpfReservation::class);
    }

    /**
     * Derive the reservation type from the sign of the quantity.
     */
    public static function typeForQty(float $qty): string
    {
        return $qty < 0 ? self::TYPE_DECREMENT : self::TYPE_INCREMENT;
    }
}
