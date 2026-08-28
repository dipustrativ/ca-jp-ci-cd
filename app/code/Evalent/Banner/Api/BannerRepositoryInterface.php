<?php


namespace Evalent\Banner\Api;

use Evalent\Banner\Api\Data\BannerInterface;
use Magento\Framework\Api\SearchCriteriaInterface;

interface BannerRepositoryInterface
{

    /**
     * Save Banner
     * @param \Evalent\Banner\Api\Data\BannerInterface $banner
     * @return \Evalent\Banner\Api\Data\BannerInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function save(
        BannerInterface $banner
    );

    /**
     * Retrieve Banner
     * @param string $bannerId
     * @return \Evalent\Banner\Api\Data\BannerInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function get($bannerId);

    /**
     * Retrieve Banner matching the specified criteria.
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
     * @return \Evalent\Banner\Api\Data\BannerSearchResultsInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getList(
        SearchCriteriaInterface $searchCriteria
    );

    /**
     * Delete Banner
     * @param \Evalent\Banner\Api\Data\BannerInterface $banner
     * @return bool true on success
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function delete(
        BannerInterface $banner
    );

    /**
     * Delete Banner by ID
     * @param string $bannerId
     * @return bool true on success
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function deleteById($bannerId);
}
