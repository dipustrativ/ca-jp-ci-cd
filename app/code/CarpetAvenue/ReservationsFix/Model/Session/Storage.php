<?php
namespace CarpetAvenue\ReservationsFix\Model\Session;

class Storage extends \Magento\Framework\Session\Storage
{
    public function __construct(
        //\Magento\Store\Model\StoreManagerInterface $storeManager,
        $namespace = 'sessionname',
        array $data = []
    ) {
        parent::__construct($namespace, $data);
    }
}