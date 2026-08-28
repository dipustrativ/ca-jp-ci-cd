<?php

declare(strict_types=1);

namespace Evalent\Banner\Ui\Component\Widget\Parameter;

use Evalent\Banner\Model\ResourceModel\Banner\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

class Banners implements OptionSourceInterface
{

    /**
     * @var \Evalent\Banner\Model\ResourceModel\Banner\CollectionFactory
     */
    private $bannerCollectionFactory;

    public function __construct(
        CollectionFactory $bannerCollectionFactory
    ) {
        $this->bannerCollectionFactory = $bannerCollectionFactory;
    }

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' =>
     *               '<label>'), ...)
     */
    public function toOptionArray(): array
    {
        /** @var \Evalent\Banner\Model\ResourceModel\Banner\Collection $banners */
        $banners = $this->bannerCollectionFactory->create()
            ->addFieldToSelect(['entity_id', 'name']);
        $return = [];
        /** @var \Evalent\Banner\Model\Banner $banner */
        foreach ($banners as $banner) {
            $return[] = ['value' => $banner->getId(), 'label' => $banner->getName()];
        }
        return $return;
    }
}