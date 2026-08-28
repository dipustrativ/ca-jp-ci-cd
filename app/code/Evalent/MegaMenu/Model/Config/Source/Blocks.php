<?php

declare(strict_types=1);

namespace Evalent\MegaMenu\Model\Config\Source;


use Magento\Cms\Model\ResourceModel\Block\CollectionFactory;
use Magento\Framework\Data\OptionSourceInterface;

class Blocks  implements OptionSourceInterface
{

    /**
     * @var \Magento\Cms\Model\ResourceModel\Block\CollectionFactory
     */
    private $blockCollectionFactory;

    public function __construct(
        CollectionFactory $blockCollectionFactory
    ) {
        $this->blockCollectionFactory = $blockCollectionFactory;
    }

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' =>
     *               '<label>'), ...)
     */
    public function toOptionArray()
    {
        $blocks = $this->blockCollectionFactory->create();
        $array = [
            [
            'value' => 0,
            'label' => "None"
            ]
        ];
        /** @var \Magento\Cms\Model\Block $block */
        foreach ($blocks as $block) {
            $array[] = [
                'value' => $block->getId(),
                'label' => $block->getTitle()
            ];
        }
        return $array;
    }
}