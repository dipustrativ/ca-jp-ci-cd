<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\Source;

use IXPF\Syncer\Model\IxpfReservation;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Delivery states for the grid's request_status filter.
 */
class ReservationStatus implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => IxpfReservation::REQUEST_PENDING, 'label' => __('Pending')],
            ['value' => IxpfReservation::REQUEST_PROCESSING, 'label' => __('Processing')],
            ['value' => IxpfReservation::REQUEST_SUCCESS, 'label' => __('Success')],
            ['value' => IxpfReservation::REQUEST_APPLIED, 'label' => __('Applied')],
            ['value' => IxpfReservation::REQUEST_FAILED, 'label' => __('Failed')],
            ['value' => IxpfReservation::REQUEST_SKIPPED, 'label' => __('Skipped')],
        ];
    }
}
