<?php


namespace Evalent\Banner\Api\Data;

interface BannerSearchResultsInterface extends \Magento\Framework\Api\SearchResultsInterface
{

    /**
     * Get Banner list.
     * @return \Evalent\Banner\Api\Data\BannerInterface[]
     */
    public function getItems();

    /**
     * Set name list.
     * @param \Evalent\Banner\Api\Data\BannerInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
