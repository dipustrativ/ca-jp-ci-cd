<?php

namespace Evalent\AdminActivityLog\Api;

interface LoginActivityRepositoryInterface
{


    /**
     * Save LoginActivity
     * @param \Evalent\AdminActivityLog\Api\Data\LoginActivityInterface $loginActivity
     * @return \Evalent\AdminActivityLog\Api\Data\LoginActivityInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function save(
        \Evalent\AdminActivityLog\Api\Data\LoginActivityInterface $loginActivity
    );

    /**
     * Retrieve LoginActivity
     * @param string $loginactivityId
     * @return \Evalent\AdminActivityLog\Api\Data\LoginActivityInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getById($loginactivityId);

    /**
     * Retrieve LoginActivity matching the specified criteria.
     * @param \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
     * @return \Evalent\AdminActivityLog\Api\Data\LoginActivitySearchResultsInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getList(
        \Magento\Framework\Api\SearchCriteriaInterface $searchCriteria
    );

    /**
     * Delete LoginActivity
     * @param \Evalent\AdminActivityLog\Api\Data\LoginActivityInterface $loginActivity
     * @return bool true on success
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function delete(
        \Evalent\AdminActivityLog\Api\Data\LoginActivityInterface $loginActivity
    );

    /**
     * Delete LoginActivity by ID
     * @param string $loginactivityId
     * @return bool true on success
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function deleteById($loginactivityId);
}
