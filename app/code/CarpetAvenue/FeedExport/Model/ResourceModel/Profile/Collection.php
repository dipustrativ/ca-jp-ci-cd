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

namespace CarpetAvenue\FeedExport\Model\ResourceModel\Profile;

use CarpetAvenue\FeedExport\Model\Profile;
use CarpetAvenue\FeedExport\Model\ResourceModel\Profile as ProfileResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    /** @var string */
    protected $_idFieldName = 'profile_id';

    protected function _construct(): void
    {
        $this->_init(Profile::class, ProfileResource::class);
    }
}
