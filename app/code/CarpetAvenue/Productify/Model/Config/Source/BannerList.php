<?php

namespace CarpetAvenue\Productify\Model\Config\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Evalent\Banner\Model\ResourceModel\Banner\CollectionFactory;

class BannerList extends AbstractSource
{
    public function __construct(
        protected CollectionFactory $bannerCollectionFactory
    ) {
    }

    public function getAllOptions()
    {
        if ($this->_options === null) {
            $this->_options = [['value' => '', 'label' => __('-- Please Select --')]];

            $collection = $this->bannerCollectionFactory->create();
            $collection->addAttributeToSelect('name');
            $collection->setOrder('name', 'ASC');

            foreach ($collection as $banner) {
                $this->_options[] = [
                    'value' => $banner->getEntityId(),
                    'label' => $banner->getName()
                ];
            }
        }

        return $this->_options;
    }
}