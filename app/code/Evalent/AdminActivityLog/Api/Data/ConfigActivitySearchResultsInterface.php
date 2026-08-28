<?php

namespace Evalent\AdminActivityLog\Api\Data;

interface ConfigActivitySearchResultsInterface extends \Magento\Framework\Api\SearchResultsInterface
{


    /**
     * Get ConfigActivity list.
     * @return \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface[]
     */
    public function getItems();

    /**
     * Set username list.
     * @param \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
