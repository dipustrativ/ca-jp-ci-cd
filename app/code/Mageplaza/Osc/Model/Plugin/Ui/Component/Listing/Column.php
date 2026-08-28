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

namespace Mageplaza\Osc\Model\Plugin\Ui\Component\Listing;

/**
 * Class Column
 * @package Mageplaza\Osc\Model\Plugin\Ui\Component\Listing
 */
class Column
{
    public function afterPrepare(\Magento\Ui\Component\Listing\Columns\Column $subject, $result)
    {
        if ($subject->getName() === 'billing_mposc_field_3') {
            $config = $subject->getData('config');
            unset($config['timezone']);
            $subject->setData('config', $config);
        }

        return $result;
    }
}
