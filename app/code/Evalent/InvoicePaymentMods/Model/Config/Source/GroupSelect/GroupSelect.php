<?php

namespace Evalent\InvoicePaymentMods\Model\Config\Source\GroupSelect;

use Magento\Customer\Api\GroupManagementInterface;

//Bound to Evalent/InvoicePaymentMethod/etc/adminhtml/system.xml to Specific Customer Groups Only Component.

/**
 * Class GroupSelect
 * @package Evalent\InvoicePaymentMods\Model\Config\Source\GroupSelect
 */
class GroupSelect implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Customer groups options array
     *
     * @var null|array
     */
    protected $_options;

    /**
     * @var GroupManagementInterface
     */
    protected $_groupManagement;

    /**
     * @var \Magento\Framework\Convert\DataObject
     */
    protected $_converter;

    /**
     * @param GroupManagementInterface $groupManagement
     * @param \Magento\Framework\Convert\DataObject $converter
     */
    public function __construct(
        GroupManagementInterface $groupManagement,
        \Magento\Framework\Convert\DataObject $converter
    )
    {
        $this->_groupManagement = $groupManagement;
        $this->_converter = $converter;
    }

    /**
     * Retrieve customer groups as array
     *
     * @return array
     */
    public function toOptionArray()
    {
        if (!$this->_options) {
            $this->_options = $this->_converter->toOptionArray(
                array_merge([$this->_groupManagement->getNotLoggedInGroup()],
                    $this->_groupManagement->getLoggedInGroups()), 'id', 'code'
            );
        }
        return $this->_options;
    }
}
