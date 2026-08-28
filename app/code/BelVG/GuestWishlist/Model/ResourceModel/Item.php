<?php
/**
 * BelVG LLC.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the EULA
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://store.belvg.com/BelVG-LICENSE-COMMUNITY.txt
 *
 ********************************************************************
 * @category   BelVG
 * @package    BelVG_GuestWishlist
 * @copyright  Copyright (c) BelVG LLC. (http://www.belvg.com)
 * @license    http://store.belvg.com/BelVG-LICENSE-COMMUNITY.txt
 */
namespace BelVG\GuestWishlist\Model\ResourceModel;

/**
 * Class Item
 * @package BelVG\GuestWishlist\Model\ResourceModel
 */
class Item extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    /**
     * Initialize connection and define main table
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('guest_wishlist_item', 'wishlist_item_id');
    }

    /**
     * Load item by wishlist, product and shared stores
     *
     * @param \BelVG\GuestWishlist\Model\Item $object
     * @param int $wishlistId
     * @param int $productId
     * @param array $sharedStores
     * @return $this
     */
    public function loadByProductWishlist($object, $wishlistId, $productId, $sharedStores)
    {
        $connection = $this->getConnection();
        $storeWhere = $connection->quoteInto('store_id IN (?)', $sharedStores);
        $select = $connection->select()->from(
            $this->getMainTable()
        )->where(
            'wishlist_id=:wishlist_id AND ' . 'product_id=:product_id AND ' . $storeWhere
        );
        $bind = ['wishlist_id' => $wishlistId, 'product_id' => $productId];
        $data = $connection->fetchRow($select, $bind);
        if ($data) {
            $object->setData($data);
        }
        $this->_afterLoad($object);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function save(\Magento\Framework\Model\AbstractModel $object)
    {
        $hasDataChanges = $object->hasDataChanges();
        $object->setIsOptionsSaved(false);

        $result = parent::save($object);

        if ($hasDataChanges && !$object->isOptionsSaved()) {
            $object->saveItemOptions();
        }
        return $result;
    }
}
