<?php

namespace Evalent\AdminActivityLog\Api\Data;

interface ModelActivitySearchResultsInterface extends \Magento\Framework\Api\SearchResultsInterface
{


    /**
     * Get ModelActivity list.
     * @return \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface[]
     */
    public function getItems();

    /**
     * Set username list.
     * @param \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
