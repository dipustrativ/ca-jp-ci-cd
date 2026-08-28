<?php

namespace Evalent\AdminActivityLog\Api;

interface ModelActivityRepositoryInterface
{


    /**
     * Save ModelActivity
     * @param \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface $modelActivity
     * @return \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function save(
        \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface $modelActivity
    );

    /**
     * Retrieve ModelActivity
     * @param string $modelactivityId
     * @return \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getById($modelactivityId);

    /**
     * Retrieve ModelActivity matching the specified criteria.
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
     * @return \Evalent\AdminActivityLog\Api\Data\ModelActivitySearchResultsInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getList(
        \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
    );

    /**
     * Delete ModelActivity
     * @param \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface $modelActivity
     * @return bool true on success
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function delete(
        \Evalent\AdminActivityLog\Api\Data\ModelActivityInterface $modelActivity
    );

    /**
     * Delete ModelActivity by ID
     * @param string $modelactivityId
     * @return bool true on success
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function deleteById($modelactivityId);
}
