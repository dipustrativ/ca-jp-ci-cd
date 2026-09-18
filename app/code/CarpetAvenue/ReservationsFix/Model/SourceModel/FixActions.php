<?php

declare(strict_types=1);

namespace CarpetAvenue\ReservationsFix\Model\SourceModel;

use Magento\Framework\Data\OptionSourceInterface;

class FixActions implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        $optionArray = [];
        $optionArray[] = ['value' => 'fix_reservation', 'label' => 'Fix reservations only'];
        $optionArray[] = ['value' => 'fix_reservation_destination', 'label' => 'Fix reservations, increase destination'];
        $optionArray[] = ['value' => 'fix_reservation_move', 'label' => 'Fix reservations, move quantity'];
        $optionArray[] = ['value' => 'skip', 'label' => 'Mark as processd, no action'];
        return $optionArray;
    }
}
