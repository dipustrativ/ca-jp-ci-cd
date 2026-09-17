<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\Source;

use IXPF\Syncer\Model\IxpfReservation;
use Magento\Framework\Data\OptionSourceInterface;

class ReservationDirection implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => IxpfReservation::DIRECTION_OUTBOUND, 'label' => __('Sent to IXPF')],
            ['value' => IxpfReservation::DIRECTION_INBOUND, 'label' => __('Received from IXPF')],
        ];
    }
}
