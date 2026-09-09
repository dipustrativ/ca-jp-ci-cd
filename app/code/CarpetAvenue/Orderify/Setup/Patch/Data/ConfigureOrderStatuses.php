<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Setup\Patch\Data;

use Magento\Sales\Model\Order\Status;
use Magento\Sales\Model\Order\StatusFactory;
use Magento\Sales\Model\ResourceModel\Order\Status as StatusResource;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates any missing order statuses and updates the labels of existing ones so
 * the full set below is present in sales_order_status (idempotent, keyed on the
 * status code).
 */
class ConfigureOrderStatuses implements DataPatchInterface
{
    /** @var array<string,string> status code => label */
    private const STATUSES = [
        'adyen_authorized'         => 'Authorized',
        'awaiting_retry'           => 'Awaiting Retry/Change Payment',
        'canceled'                 => 'Canceled',
        'closed'                   => 'Closed',
        'complete'                 => 'Complete',
        'delivered'                => 'Delivered',
        'fraud'                    => 'Fraud',
        'holded'                   => 'On Hold',
        'jp_transit'               => 'JP Transit',
        'order_canceled'           => 'Order Canceled',
        'payment_review'           => 'Payment Review',
        'paypal_canceled_reversal' => 'PayPal Canceled Reversal',
        'paypal_reversed'          => 'PayPal Reversed',
        'pending'                  => 'Pending',
        'pending_payment'          => 'Pending Payment',
        'pending_paypal'           => 'Pending PayPal',
        'pending_unpaid'           => 'Pending Unpaid',
        'picking'                  => 'Picking',
        'processing'               => 'Processing',
        'processing_in_progress'   => 'Processing In Progress',
        'processing_unpaid'        => 'Processing Unpaid',
        'ready_for_handling'       => 'Ready For Handling',
        'refunded'                 => 'Refunded',
        'reservation'              => 'Reservation',
        'shipped'                  => 'Shipped',
    ];

    public function __construct(
        private ModuleDataSetupInterface $moduleDataSetup,
        private StatusFactory $statusFactory,
        private StatusResource $statusResource
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        foreach (self::STATUSES as $code => $label) {
            /** @var Status $status */
            $status = $this->statusFactory->create();
            $this->statusResource->load($status, $code, 'status');

            $status->setStatus($code)->setLabel($label);
            $this->statusResource->save($status);
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
