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
use CarpetAvenue\FeedExport\Model\ResourceModel\Profile as ProfileResource;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Model\AbstractModel;

class Profile extends AbstractModel implements ProfileInterface, IdentityInterface
{
    public const CACHE_TAG = 'carpetavenue_feedexport_profile';

    /** @var string */
    protected $_eventPrefix = 'carpetavenue_feedexport_profile';

    /** @var string */
    protected $_cacheTag = self::CACHE_TAG;

    protected function _construct(): void
    {
        $this->_init(ProfileResource::class);
    }

    public function getIdentities(): array
    {
        return [self::CACHE_TAG . '_' . $this->getId()];
    }

    public function getProfileId(): ?int
    {
        $id = $this->getData(self::PROFILE_ID);
        return $id === null ? null : (int)$id;
    }

    public function setProfileId(int $profileId): self
    {
        return $this->setData(self::PROFILE_ID, $profileId);
    }

    public function getName(): string
    {
        return (string)$this->getData(self::NAME);
    }

    public function setName(string $name): self
    {
        return $this->setData(self::NAME, $name);
    }

    public function isEnabled(): bool
    {
        return (bool)$this->getData(self::ENABLED);
    }

    public function setEnabled(bool $enabled): self
    {
        return $this->setData(self::ENABLED, (int)$enabled);
    }

    public function getStoreId(): int
    {
        return (int)$this->getData(self::STORE_ID);
    }

    public function setStoreId(int $storeId): self
    {
        return $this->setData(self::STORE_ID, $storeId);
    }

    public function getCustomerGroupId(): int
    {
        return (int)$this->getData(self::CUSTOMER_GROUP_ID);
    }

    public function setCustomerGroupId(int $customerGroupId): self
    {
        return $this->setData(self::CUSTOMER_GROUP_ID, $customerGroupId);
    }

    public function getExcludedProductTypes(): array
    {
        return $this->splitList((string)$this->getData(self::EXCLUDED_PRODUCT_TYPES));
    }

    public function setExcludedProductTypes(array $types): self
    {
        return $this->setData(self::EXCLUDED_PRODUCT_TYPES, implode(',', $types));
    }

    public function getProductStatus(): array
    {
        return array_map('intval', $this->splitList((string)$this->getData(self::PRODUCT_STATUS)));
    }

    public function setProductStatus(array $statuses): self
    {
        return $this->setData(self::PRODUCT_STATUS, implode(',', $statuses));
    }

    public function getProductVisibility(): array
    {
        return array_map('intval', $this->splitList((string)$this->getData(self::PRODUCT_VISIBILITY)));
    }

    public function setProductVisibility(array $visibilities): self
    {
        return $this->setData(self::PRODUCT_VISIBILITY, implode(',', $visibilities));
    }

    public function isInstockOnly(): bool
    {
        return (bool)$this->getData(self::INSTOCK_ONLY);
    }

    public function setInstockOnly(bool $instockOnly): self
    {
        return $this->setData(self::INSTOCK_ONLY, (int)$instockOnly);
    }

    public function getSkuFilter(): array
    {
        return $this->splitList((string)$this->getData(self::SKU_FILTER));
    }

    public function setSkuFilter(?string $skuFilter): self
    {
        return $this->setData(self::SKU_FILTER, $skuFilter);
    }

    public function getReplaceNewlines(): int
    {
        return (int)$this->getData(self::REPLACE_NEWLINES);
    }

    public function setReplaceNewlines(int $mode): self
    {
        return $this->setData(self::REPLACE_NEWLINES, $mode);
    }

    public function isStripTags(): bool
    {
        return (bool)$this->getData(self::STRIP_TAGS);
    }

    public function setStripTags(bool $stripTags): self
    {
        return $this->setData(self::STRIP_TAGS, (int)$stripTags);
    }

    public function isUrlRemoveStore(): bool
    {
        return (bool)$this->getData(self::URL_REMOVE_STORE);
    }

    public function setUrlRemoveStore(bool $remove): self
    {
        return $this->setData(self::URL_REMOVE_STORE, (int)$remove);
    }

    public function isRemovePubFromUrls(): bool
    {
        return (bool)$this->getData(self::REMOVE_PUB_FROM_URLS);
    }

    public function setRemovePubFromUrls(bool $remove): self
    {
        return $this->setData(self::REMOVE_PUB_FROM_URLS, (int)$remove);
    }

    public function getXslTemplate(): string
    {
        return (string)$this->getData(self::XSL_TEMPLATE);
    }

    public function setXslTemplate(string $xslTemplate): self
    {
        return $this->setData(self::XSL_TEMPLATE, $xslTemplate);
    }

    public function getCategoryMapping(): array
    {
        $json = (string)$this->getData(self::CATEGORY_MAPPING);
        if ($json === '') {
            return [];
        }
        $mapping = json_decode($json, true);
        return is_array($mapping) ? $mapping : [];
    }

    public function setCategoryMapping(?string $json): self
    {
        return $this->setData(self::CATEGORY_MAPPING, $json);
    }

    public function getTaxonomySource(): string
    {
        return (string)$this->getData(self::TAXONOMY_SOURCE);
    }

    public function setTaxonomySource(?string $source): self
    {
        return $this->setData(self::TAXONOMY_SOURCE, $source);
    }

    public function getDestinationPath(): string
    {
        return (string)$this->getData(self::DESTINATION_PATH);
    }

    public function setDestinationPath(string $path): self
    {
        return $this->setData(self::DESTINATION_PATH, $path);
    }

    public function isCronEnabled(): bool
    {
        return (bool)$this->getData(self::CRON_ENABLED);
    }

    public function setCronEnabled(bool $enabled): self
    {
        return $this->setData(self::CRON_ENABLED, (int)$enabled);
    }

    public function getCronExpressions(): array
    {
        $expressions = array_map('trim', explode(';', (string)$this->getData(self::CRON_EXPRESSION)));
        return array_values(array_unique(array_filter($expressions, 'strlen')));
    }

    public function setCronExpression(?string $expression): self
    {
        return $this->setData(self::CRON_EXPRESSION, $expression);
    }

    public function getLastExecution(): ?string
    {
        $value = $this->getData(self::LAST_EXECUTION);
        return $value === null ? null : (string)$value;
    }

    public function setLastExecution(?string $dateTime): self
    {
        return $this->setData(self::LAST_EXECUTION, $dateTime);
    }

    /**
     * @param string $value Comma or newline separated list
     * @return string[]
     */
    private function splitList(string $value): array
    {
        $parts = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_values(array_unique($parts));
    }
}
