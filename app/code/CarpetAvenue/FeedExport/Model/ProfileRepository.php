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

namespace CarpetAvenue\FeedExport\Model;

use CarpetAvenue\FeedExport\Api\Data\ProfileInterface;
use CarpetAvenue\FeedExport\Api\Data\ProfileSearchResultsInterface;
use CarpetAvenue\FeedExport\Api\Data\ProfileSearchResultsInterfaceFactory;
use CarpetAvenue\FeedExport\Api\ProfileRepositoryInterface;
use CarpetAvenue\FeedExport\Model\ResourceModel\Profile as ProfileResource;
use CarpetAvenue\FeedExport\Model\ResourceModel\Profile\CollectionFactory;
use Magento\Framework\Api\SearchCriteria\CollectionProcessorInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;

class ProfileRepository implements ProfileRepositoryInterface
{
    public function __construct(
        private readonly ProfileResource $resource,
        private readonly ProfileFactory $profileFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly CollectionProcessorInterface $collectionProcessor,
        private readonly ProfileSearchResultsInterfaceFactory $searchResultsFactory
    ) {
    }

    public function save(ProfileInterface $profile): ProfileInterface
    {
        try {
            /** @var Profile $profile */
            $this->resource->save($profile);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save feed profile: %1', $e->getMessage()), $e);
        }
        return $profile;
    }

    public function getById(int $profileId): ProfileInterface
    {
        $profile = $this->profileFactory->create();
        $this->resource->load($profile, $profileId);
        if (!$profile->getId()) {
            throw new NoSuchEntityException(__('Feed profile with ID "%1" does not exist.', $profileId));
        }
        return $profile;
    }

    public function getList(SearchCriteriaInterface $searchCriteria): ProfileSearchResultsInterface
    {
        $collection = $this->collectionFactory->create();
        $this->collectionProcessor->process($searchCriteria, $collection);

        $searchResults = $this->searchResultsFactory->create();
        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());
        return $searchResults;
    }

    public function delete(ProfileInterface $profile): bool
    {
        try {
            /** @var Profile $profile */
            $this->resource->delete($profile);
        } catch (\Exception $e) {
            throw new CouldNotDeleteException(__('Could not delete feed profile: %1', $e->getMessage()), $e);
        }
        return true;
    }

    public function deleteById(int $profileId): bool
    {
        return $this->delete($this->getById($profileId));
    }
}
