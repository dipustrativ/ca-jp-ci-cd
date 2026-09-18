<?php

declare(strict_types=1);

namespace CarpetAvenue\ReservationsFix\Plugin\Inventory\Cli;

use Magento\InventoryReservationCli\Model\ResourceModel\GetReservationsList as ReservationsList;
use CarpetAvenue\ReservationsFix\Helper\Data;
use Magento\Framework\Serialize\SerializerInterface;

class GetReservationsList{
    protected Data $dataHelper;
    protected SerializerInterface $serializer;
    public function __construct(
        Data $dataHelper,
        SerializerInterface $serializer
    ){
        $this->dataHelper = $dataHelper;
        $this->serializer = $serializer;
    }
    public function afterExecute(ReservationsList $subject, array $reservations){
        if (!$this->dataHelper->isEnabled() || !$this->dataHelper->cliIncludeCustomReservations()) {
            $reservations;
        }

        foreach($reservations as $key => $reservation){
            /** @var array $metadata */
            $metadata = $this->serializer->unserialize($reservation['metadata']);
            if ($metadata['object_type'] == 'ca_order_adjust') {
                $metadata['object_type'] == 'order';
            }
        }
        return $reservations;
    }
}