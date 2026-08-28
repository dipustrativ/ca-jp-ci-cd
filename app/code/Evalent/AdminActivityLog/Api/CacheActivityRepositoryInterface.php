<?php

namespace Evalent\AdminActivityLog\Api;

interface CacheActivityRepositoryInterface
{


    /**
     * Save CacheActivity
     *
     * @param \Evalent\AdminActivityLog\Api\Data\CacheActivityInterface $cacheActivity
     *
     * @return \Evalent\AdminActivityLog\Api\Data\CacheActivityInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function save(
        \Evalent\AdminActivityLog\Api\Data\CacheActivityInterface $cacheActivity
    );

    /**
     * Retrieve CacheActivity
     * @param string $modelactivityId
     * @return \Evalent\AdminActivityLog\Api\Data\CacheActivityInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getById($modelactivityId);

    /**
     * Retrieve CacheActivity matching the specified criteria.
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
     * @return \Evalent\AdminActivityLog\Api\Data\CacheActivitySearchResultsInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getList(
        \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
    );

    /**
     * Delete CacheActivity
     * @param \Evalent\AdminActivityLog\Api\Data\CacheActivityInterface $modelActivity
     * @return bool true on success
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function delete(
        \Evalent\AdminActivityLog\Api\Data\CacheActivityInterface $modelActivity
    );

    /**
     * Delete CacheActivity by ID
     * @param string $modelactivityId
     * @return bool true on success
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function deleteById($modelactivityId);
}
