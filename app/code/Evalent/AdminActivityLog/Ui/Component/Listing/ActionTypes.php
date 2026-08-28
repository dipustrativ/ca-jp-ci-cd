<?php

declare(strict_types=1);

namespace Evalent\AdminActivityLog\Ui\Component\Listing;

use Evalent\AdminActivityLog\Model\ModelActivity;

class ActionTypes implements \Magento\Framework\Data\OptionSourceInterface
{

    /**
     * @return array|null
     */
    public function toOptionArray()
    {
        $options = [];
        foreach (ModelActivity::ACTION_TYPES as $value) {
            $options[] = [
                'value' => $value,
                'label' => __($value)
            ];
        }
        return $options;
    }
}
