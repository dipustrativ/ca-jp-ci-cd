<?php
/**
 * Created by PhpStorm.
 * User: bassam
 * Date: 2018-10-25
 * Time: 11:08
 */

namespace Evalent\InvoicePaymentMods\Model\Config\Source\Allcustomer;

use Magento\Framework\Option\ArrayInterface;

//Bound to Evalent/InvoicePaymentMethod/etc/adminhtml/system.xml to Applicable Customer Groups Component.

/**
 * Class Allcustomer
 * @package Evalent\InvoicePaymentMods\Model\Config\Source\Allcustomer
 */
class Allcustomer implements ArrayInterface
{
    /**
     * @var
     */
    protected $_options;

    /**
     * @return array
     */
    public function toOptionArray()
    {
        if (!$this->_options) {
            $this->_options = [['value' => 0, 'label' => __('Allow All Customer Groups')],
                ['value' => 1, 'label' => __('Allow Specific Customer Groups')]];
        }
        return $this->_options;
    }
}