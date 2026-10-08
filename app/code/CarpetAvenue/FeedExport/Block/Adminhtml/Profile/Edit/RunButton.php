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

namespace CarpetAvenue\FeedExport\Block\Adminhtml\Profile\Edit;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class RunButton extends GenericButton implements ButtonProviderInterface
{
    public function getButtonData(): array
    {
        if (!$this->getProfileId() || !$this->authorization->isAllowed('CarpetAvenue_FeedExport::run')) {
            return [];
        }
        return [
            'label' => __('Run Export Now'),
            'class' => 'action-secondary',
            'on_click' => sprintf(
                "confirmSetLocation('%s', '%s')",
                __('Generate the feed now with the last saved settings? Unsaved changes are ignored.'),
                $this->getUrl('*/*/run', ['profile_id' => $this->getProfileId(), 'from_edit' => 1])
            ),
            'sort_order' => 30,
        ];
    }
}
