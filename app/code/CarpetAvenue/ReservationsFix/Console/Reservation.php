<?php
namespace CarpetAvenue\ReservationsFix\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use CarpetAvenue\ReservationsFix\Helper\Data;
use CarpetAvenue\ReservationsFix\Api\ReservationsManagerInterface;
use Magento\InventoryReservationsApi\Model\ReservationBuilderInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\InventoryReservations\Model\ResourceModel\SaveMultiple as SaveMultipleReservations;

class Reservation extends Command
{
    const INCREMENT_ID = 'increment_id';

    protected Data $dataHelper;
    protected ReservationsManagerInterface $reservationsManager;
    protected ReservationBuilderInterface $reservationBuilder;
    protected SerializerInterface $serializer;
    protected SaveMultipleReservations $saveMultipleReservations;

    public function __construct(
        string $name,
        Data $dataHelper,
        ReservationsManagerInterface $reservationsManager,
        ReservationBuilderInterface $reservationBuilder,
        SerializerInterface $serializer,
        SaveMultipleReservations $saveMultipleReservations
    ) {
        $this->dataHelper = $dataHelper;
        $this->reservationsManager = $reservationsManager;
        $this->reservationBuilder = $reservationBuilder;
        $this->serializer = $serializer;
        $this->saveMultipleReservations = $saveMultipleReservations;
        parent::__construct($name);
    }
    protected function configure()
    {
        $this->setName('reservation:fix:init');
        $this->setDescription('Initialize reservations');
        $this->addOption(
            self::INCREMENT_ID,
            null,
            InputOption::VALUE_OPTIONAL,
            'Order increment id'
        );

        parent::configure();
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($incerementId = $input->getOption(self::INCREMENT_ID)) {
            $output->writeln('<info>Create adjust reservations for order: ' . $incerementId . '</info>');
            $this->processOrder($incerementId);
        }
        else{
            $orders = $this->reservationsManager->getOrdersWithReservations();
            foreach ($orders as $order){
                $this->processOrder($order['increment_id']);
            }
        }
        return 0;
    }

    protected function processOrder($incrementId){
        $reservations = $this->reservationsManager->getReservationsForOrder($incrementId, 'custom');
        if (0 < count($reservations)){
            return;
        }
        $enabledStockId = $this->dataHelper->getEnabledStockId();
        $reservations = $this->reservationsManager->getReservationsForOrder($incrementId, 'base');
        $adjustingReservations = [];
        foreach ($reservations as $reservation){
            if ($enabledStockId == $reservation['stock_id']){
                continue;
            }
            $metadata = $this->serializer->unserialize($reservation['metadata']);
            $metadata['object_type'] = 'ca_order_adjust';
            $adjustingReservations[] = $this->reservationBuilder->setSku($reservation['sku'])
            ->setQuantity((float)$reservation['quantity'])
            ->setStockId((int)$enabledStockId)
            ->setMetadata($this->serializer->serialize($metadata))
            ->build();

        }
        $this->saveMultipleReservations->execute($adjustingReservations);
    }
}