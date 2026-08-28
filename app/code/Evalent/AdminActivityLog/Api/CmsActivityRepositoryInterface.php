<?php

namespace Evalent\AdminActivityLog\Api;

interface CmsActivityRepositoryInterface
{


    /**
     * Save LoginActivity
     * @param \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface $cmsActivity
     * @return \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function save(
        \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface $cmsActivity
    );

    /**
     * Retrieve LoginActivity
     * @param string $loginactivityId
     * @return \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getById($cmsactivityId);

    /**
     * Retrieve LoginActivity matching the specified criteria.
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
     * @return \Evalent\AdminActivityLog\Api\Data\CmsActivitySearchResultsInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getList(
        \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
    );

    /**
     * Delete LoginActivity
     * @param \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface $cmsActivity
     * @return bool true on success
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function delete(
        \Evalent\AdminActivityLog\Api\Data\CmsActivityInterface $cmsActivity
    );

    /**
     * Delete LoginActivity by ID
     * @param string $cmsactivityId
     * @return bool true on success
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function deleteById($cmsactivityId);
}
