<?php

namespace Evalent\AdminActivityLog\Api\Data;

interface CacheActivitySearchResultsInterface extends \Magento\Framework\Api\SearchResultsInterface
{


    /**
     * Get ModelActivity list.
     * @return \Evalent\AdminActivityLog\Api\Data\CacheActivityInterface[]
     */
    public function getItems();

    /**
     * Set username list.
     * @param \Evalent\AdminActivityLog\Api\Data\CacheActivityInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
