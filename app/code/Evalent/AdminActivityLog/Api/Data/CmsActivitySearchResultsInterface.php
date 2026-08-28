<?php

namespace Evalent\AdminActivityLog\Api\Data;

interface CmsActivitySearchResultsInterface extends \Magento\Framework\Api\SearchResultsInterface
{


    /**
     * Get CmsActivity list.
     * @return \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface[]
     */
    public function getItems();

    /**
     * Set username list.
     * @param \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
