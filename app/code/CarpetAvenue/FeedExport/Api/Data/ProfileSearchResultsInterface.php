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

use Magento\Framework\Api\SearchResultsInterface;

/**
 * @api
 */
interface ProfileSearchResultsInterface extends SearchResultsInterface
{
    /**
     * @return \CarpetAvenue\FeedExport\Api\Data\ProfileInterface[]
     */
    public function getItems();

    /**
     * @param \CarpetAvenue\FeedExport\Api\Data\ProfileInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
