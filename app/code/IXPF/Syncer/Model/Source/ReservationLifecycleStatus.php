<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\Source;

use IXPF\Syncer\Model\IxpfReservation;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Business lifecycle stages — the value sent to IXPF as `status`.
 */
class ReservationLifecycleStatus implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => IxpfReservation::RESERVATION_PENDING, 'label' => __('Pending')],
            ['value' => IxpfReservation::RESERVATION_COMPLETED, 'label' => __('Completed')],
            ['value' => IxpfReservation::RESERVATION_CANCELLED, 'label' => __('Cancelled')],
            ['value' => IxpfReservation::RESERVATION_REFUNDED, 'label' => __('Refunded')],
        ];
    }
}
