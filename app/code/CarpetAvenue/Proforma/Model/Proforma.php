<?php

namespace CarpetAvenue\Proforma\Model;

class Proforma extends \Magento\Framework\Model\AbstractModel{
    public const ENTITY_TYPE = 'proforma';
    protected $items = [];
    public function _construct(): void
    {
        $this->_init(\CarpetAvenue\Proforma\Model\ResourceModel\Proforma::class);
    }
    public function addItem(\CarpetAvenue\Proforma\Model\ProformaItem $item): static{
        $this->items[] = $item;
        return $this;
    }
    public function getItems(): array{
        return $this->items;
    }
}