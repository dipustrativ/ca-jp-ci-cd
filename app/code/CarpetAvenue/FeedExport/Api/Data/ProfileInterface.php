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

namespace CarpetAvenue\FeedExport\Api\Data;

/**
 * Feed export profile: which products, which store, which XSL template, where and when.
 *
 * @api
 */
interface ProfileInterface
{
    public const PROFILE_ID = 'profile_id';
    public const NAME = 'name';
    public const ENABLED = 'enabled';
    public const STORE_ID = 'store_id';
    public const CUSTOMER_GROUP_ID = 'customer_group_id';
    public const EXCLUDED_PRODUCT_TYPES = 'excluded_product_types';
    public const PRODUCT_STATUS = 'product_status';
    public const PRODUCT_VISIBILITY = 'product_visibility';
    public const INSTOCK_ONLY = 'instock_only';
    public const SKU_FILTER = 'sku_filter';
    public const REPLACE_NEWLINES = 'replace_newlines';
    public const STRIP_TAGS = 'strip_tags';
    public const URL_REMOVE_STORE = 'url_remove_store';
    public const REMOVE_PUB_FROM_URLS = 'remove_pub_from_urls';
    public const XSL_TEMPLATE = 'xsl_template';
    public const CATEGORY_MAPPING = 'category_mapping';
    public const TAXONOMY_SOURCE = 'taxonomy_source';
    public const DESTINATION_PATH = 'destination_path';
    public const CRON_ENABLED = 'cron_enabled';
    public const CRON_EXPRESSION = 'cron_expression';
    public const XTENTO_PROFILE_ID = 'xtento_profile_id';
    public const LAST_EXECUTION = 'last_execution';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';

    public const NEWLINES_KEEP = 0;
    public const NEWLINES_BR = 1;
    public const NEWLINES_SPACE = 2;
    public const NEWLINES_REMOVE = 3;

    /**
     * @return int|null
     */
    public function getProfileId(): ?int;

    /**
     * @param int $profileId
     * @return $this
     */
    public function setProfileId(int $profileId): self;

    /**
     * @return string
     */
    public function getName(): string;

    /**
     * @param string $name
     * @return $this
     */
    public function setName(string $name): self;

    /**
     * @return bool
     */
    public function isEnabled(): bool;

    /**
     * @param bool $enabled
     * @return $this
     */
    public function setEnabled(bool $enabled): self;

    /**
     * @return int
     */
    public function getStoreId(): int;

    /**
     * @param int $storeId
     * @return $this
     */
    public function setStoreId(int $storeId): self;

    /**
     * @return int
     */
    public function getCustomerGroupId(): int;

    /**
     * @param int $customerGroupId
     * @return $this
     */
    public function setCustomerGroupId(int $customerGroupId): self;

    /**
     * @return string[]
     */
    public function getExcludedProductTypes(): array;

    /**
     * @param string[] $types
     * @return $this
     */
    public function setExcludedProductTypes(array $types): self;

    /**
     * @return int[]
     */
    public function getProductStatus(): array;

    /**
     * @param int[] $statuses
     * @return $this
     */
    public function setProductStatus(array $statuses): self;

    /**
     * @return int[]
     */
    public function getProductVisibility(): array;

    /**
     * @param int[] $visibilities
     * @return $this
     */
    public function setProductVisibility(array $visibilities): self;

    /**
     * @return bool
     */
    public function isInstockOnly(): bool;

    /**
     * @param bool $instockOnly
     * @return $this
     */
    public function setInstockOnly(bool $instockOnly): self;

    /**
     * @return string[]
     */
    public function getSkuFilter(): array;

    /**
     * @param string|null $skuFilter Comma or newline separated SKUs
     * @return $this
     */
    public function setSkuFilter(?string $skuFilter): self;

    /**
     * @return int One of NEWLINES_* constants
     */
    public function getReplaceNewlines(): int;

    /**
     * @param int $mode
     * @return $this
     */
    public function setReplaceNewlines(int $mode): self;

    /**
     * @return bool
     */
    public function isStripTags(): bool;

    /**
     * @param bool $stripTags
     * @return $this
     */
    public function setStripTags(bool $stripTags): self;

    /**
     * @return bool
     */
    public function isUrlRemoveStore(): bool;

    /**
     * @param bool $remove
     * @return $this
     */
    public function setUrlRemoveStore(bool $remove): self;

    /**
     * @return bool
     */
    public function isRemovePubFromUrls(): bool;

    /**
     * @param bool $remove
     * @return $this
     */
    public function setRemovePubFromUrls(bool $remove): self;

    /**
     * @return string
     */
    public function getXslTemplate(): string;

    /**
     * @param string $xslTemplate
     * @return $this
     */
    public function setXslTemplate(string $xslTemplate): self;

    /**
     * Category ID => taxonomy path.
     *
     * @return array<int|string, string>
     */
    public function getCategoryMapping(): array;

    /**
     * @param string|null $json
     * @return $this
     */
    public function setCategoryMapping(?string $json): self;

    /**
     * Taxonomy code (see Model\Taxonomy) used by the category mapping autocomplete, '' = none.
     *
     * @return string
     */
    public function getTaxonomySource(): string;

    /**
     * @param string|null $source
     * @return $this
     */
    public function setTaxonomySource(?string $source): self;

    /**
     * @return string
     */
    public function getDestinationPath(): string;

    /**
     * @param string $path Relative to Magento root
     * @return $this
     */
    public function setDestinationPath(string $path): self;

    /**
     * @return bool
     */
    public function isCronEnabled(): bool;

    /**
     * @param bool $enabled
     * @return $this
     */
    public function setCronEnabled(bool $enabled): self;

    /**
     * @return string[]
     */
    public function getCronExpressions(): array;

    /**
     * @param string|null $expression Semicolon separated cron expressions
     * @return $this
     */
    public function setCronExpression(?string $expression): self;

    /**
     * @return string|null
     */
    public function getLastExecution(): ?string;

    /**
     * @param string|null $dateTime
     * @return $this
     */
    public function setLastExecution(?string $dateTime): self;
}
