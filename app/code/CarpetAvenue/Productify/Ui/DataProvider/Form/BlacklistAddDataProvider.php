<?php

namespace CarpetAvenue\Productify\Ui\DataProvider\Form;

use Magento\Ui\DataProvider\AbstractDataProvider;
use CarpetAvenue\Productify\Model\ResourceModel\CaJpBlacklist\CollectionFactory;

class BlacklistAddDataProvider extends AbstractDataProvider
{
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    public function getData(): array
    {
        // For "add new" form, returning empty can be fine
        return [];
    }
}
