<?php
/**
 * Created by PhpStorm.
 * User: bassam
 * Date: 2018-10-25
 * Time: 11:11
 */

namespace Evalent\InvoicePaymentMods\Model\Config\Source\Invoicestatus;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Framework\Data\OptionSourceInterface;

//Bound to Evalent/InvoicePaymentMethod/etc/adminhtml/system.xml to Invoice Status Only Component.


/**
 * Class InvoiceStatusValues
 * @package Evalent\InvoicePaymentMods\Model\Config\Source\Invoicestatus
 */
class InvoiceStatusValues extends AbstractSource implements OptionSourceInterface
{
    /**
     * @return array
     */
    public function getAllOptions()
    {
        return [
            ['label' => __('Open'), 'value' => \Magento\Sales\Model\Order\Invoice::STATE_OPEN],
            ['label' => __('Paid'), 'value' => \Magento\Sales\Model\Order\Invoice::STATE_PAID]
        ];
    }
}