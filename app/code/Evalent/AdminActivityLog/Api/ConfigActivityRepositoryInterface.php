<?php

namespace Evalent\AdminActivityLog\Api;

interface ConfigActivityRepositoryInterface
{


    /**
     * Save ModelActivity
     * @param \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface $configActivity
     * @return \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function save(
        \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface $configActivity
    );

    /**
     * Retrieve ModelActivity
     * @param string $configactivityId
     * @return \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getById($configactivityId);

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
     * @param \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface $configActivity
     * @return bool true on success
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function delete(
        \Evalent\AdminActivityLog\Api\Data\ConfigActivityInterface $configActivity
    );

    /**
     * Delete ModelActivity by ID
     * @param string $configactivityId
     * @return bool true on success
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function deleteById($configactivityId);
}
