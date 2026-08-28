<?php

namespace Evalent\AdminActivityLog\Api\Data;

interface LoginActivitySearchResultsInterface extends \Magento\Framework\Api\SearchResultsInterface
{


    /**
     * Get LoginActivity list.
     * @return \Evalent\AdminActivityLog\Api\Data\LoginActivityInterface[]
     */
    public function getItems();

    /**
     * Set username list.
     * @param \Evalent\AdminActivityLog\Api\Data\LoginActivityInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
