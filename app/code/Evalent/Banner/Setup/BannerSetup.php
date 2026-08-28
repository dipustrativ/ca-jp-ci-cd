<?php

declare(strict_types=1);

namespace Evalent\Banner\Setup;

use Evalent\Banner\Model\Banner;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;

class BannerSetup extends EavSetup
{
    /**
     * Default entities and attributes
     *
     * @return array
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function getDefaultEntities()
    {
        return [
            Banner::ENTITY => [
                'entity_model' => \Evalent\Banner\Model\ResourceModel\Banner::class,
                'attribute_model' => Attribute::class,
                'table' => 'evalent_banner_banner_entity',
                'attributes' => [
                    'name' => [
                        'type' => 'static'
                    ]
                ],
            ]
        ];
    }
}