<?php
declare(strict_types=1);

namespace CarpetAvenue\Productify\Model\Config\Source;

use Magento\Cms\Model\ResourceModel\Block\CollectionFactory;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;

class BlockList extends AbstractSource
{
    public function __construct(
        private readonly CollectionFactory $blockCollectionFactory
    )
    {
    }

    public function getAllOptions(): array
    {
        $this->_options = [['value' => '', 'label' => __('-- Please Select --')]];
        $collection = $this->blockCollectionFactory->create();
        $collection->addFieldToFilter('is_active', 1);
        foreach ($collection as $block) {
            $this->_options[] = [
                'value' => $block->getData('identifier'),
                'label' => $block->getTitle()
            ];
        }
        return $this->_options;
    }
}
