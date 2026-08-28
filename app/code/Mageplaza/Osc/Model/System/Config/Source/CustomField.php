<?php
/**
 * Mageplaza
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Mageplaza.com license that is
 * available through the world-wide-web at this URL:
 * https://www.mageplaza.com/LICENSE.txt
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Mageplaza
 * @package     Mageplaza_Osc
 * @copyright   Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license     https://www.mageplaza.com/LICENSE.txt
 */

namespace Mageplaza\Osc\Model\System\Config\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Class CustomField
 * @package Mageplaza\Osc\Model\System\Config\Source
 */
class CustomField implements ArrayInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray()
    {
        $result = [];

        $result[] = ['value' => '', 'label' => __('-- Please select --')];
        for ($i = 1; $i <= 3; $i++) {
            $result[] = ['value' => 'mposc_field_' . $i, 'label' => __('Custom Field %1', $i)];
        }

        return $result;
    }
}
