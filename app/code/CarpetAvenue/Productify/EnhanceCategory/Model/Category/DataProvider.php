<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\EnhanceCategory\Model\Category;

class DataProvider extends \Magento\Catalog\Model\Category\DataProvider
{
    protected function getFieldsMap()
    {
        $fields = parent::getFieldsMap();
        $fields['content'][] = 'thumbnail';
        $fields['content'][] = 'mobile_image';

        return $fields;
    }
}
