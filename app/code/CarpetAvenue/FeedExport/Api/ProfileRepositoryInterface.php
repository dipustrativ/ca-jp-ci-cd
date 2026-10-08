<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_FeedExport
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\FeedExport\Api;

use CarpetAvenue\FeedExport\Api\Data\ProfileInterface;
use CarpetAvenue\FeedExport\Api\Data\ProfileSearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaInterface;

/**
 * @api
 */
interface ProfileRepositoryInterface
{
    /**
     * @param ProfileInterface $profile
     * @return ProfileInterface
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function save(ProfileInterface $profile): ProfileInterface;

    /**
     * @param int $profileId
     * @return ProfileInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getById(int $profileId): ProfileInterface;

    /**
     * @param SearchCriteriaInterface $searchCriteria
     * @return ProfileSearchResultsInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): ProfileSearchResultsInterface;

    /**
     * @param ProfileInterface $profile
     * @return bool
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function delete(ProfileInterface $profile): bool;

    /**
     * @param int $profileId
     * @return bool
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     */
    public function deleteById(int $profileId): bool;
}
