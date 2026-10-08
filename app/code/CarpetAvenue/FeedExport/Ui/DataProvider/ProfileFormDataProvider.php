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

namespace CarpetAvenue\FeedExport\Ui\DataProvider;

use CarpetAvenue\FeedExport\Api\Data\ProfileInterface;
use CarpetAvenue\FeedExport\Model\ResourceModel\Profile\CollectionFactory;
use Magento\Backend\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;

class ProfileFormDataProvider extends AbstractDataProvider
{
    /** @var array<int|string, array<string, mixed>>|null */
    private ?array $loadedData = null;

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     */
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly RequestInterface $request,
        private readonly Session $session,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $collectionFactory->create();
    }

    public function getData(): array
    {
        if ($this->loadedData !== null) {
            return $this->loadedData;
        }
        $this->loadedData = [];

        $profileId = (int)$this->request->getParam($this->requestFieldName);
        if ($profileId) {
            $this->collection->addFieldToFilter(ProfileInterface::PROFILE_ID, $profileId);
            foreach ($this->collection->getItems() as $profile) {
                $row = $profile->getData();
                foreach ([ProfileInterface::EXCLUDED_PRODUCT_TYPES, ProfileInterface::PRODUCT_VISIBILITY] as $field) {
                    $row[$field] = array_values(array_filter(explode(',', (string)($row[$field] ?? '')), 'strlen'));
                }
                $this->loadedData[$profile->getId()] = $row;
            }
        }

        // Re-populate the form after a failed save.
        $formData = $this->session->getFormData(true);
        if (is_array($formData) && $formData) {
            $this->loadedData[$profileId ?: ''] = $formData;
        }
        return $this->loadedData;
    }
}
