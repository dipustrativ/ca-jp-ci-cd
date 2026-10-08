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

use CarpetAvenue\FeedExport\Model\Log;
use CarpetAvenue\FeedExport\Model\ResourceModel\Log\CollectionFactory;
use CarpetAvenue\FeedExport\Model\Source\LogStatus;
use CarpetAvenue\FeedExport\Model\Source\Trigger;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/**
 * "Profile Execution Log" tab: latest runs of this profile.
 */
class ExecutionLog extends Template
{
    private const LIMIT = 50;

    /**
     * @var string
     */
    protected $_template = 'CarpetAvenue_FeedExport::profile/execution_log.phtml';

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly CollectionFactory $logCollectionFactory,
        private readonly LogStatus $logStatus,
        private readonly Trigger $trigger,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getProfileId(): int
    {
        return (int)$this->getRequest()->getParam('profile_id');
    }

    /**
     * @return Log[]
     */
    public function getEntries(): array
    {
        if (!$this->getProfileId()) {
            return [];
        }
        $collection = $this->logCollectionFactory->create();
        $collection->addFieldToFilter('profile_id', $this->getProfileId())
            ->setOrder('log_id', 'DESC')
            ->setPageSize(self::LIMIT);
        return array_values($collection->getItems());
    }

    public function getStatusLabel(int $status): string
    {
        return $this->getOptionLabel($this->logStatus->toOptionArray(), $status);
    }

    public function getTriggerLabel(string $trigger): string
    {
        return $this->getOptionLabel($this->trigger->toOptionArray(), $trigger);
    }

    public function formatExecutedAt(string $dateTime): string
    {
        return $this->formatDate($dateTime, \IntlDateFormatter::MEDIUM, true);
    }

    public function getLogGridUrl(): string
    {
        return $this->getUrl('*/log/index');
    }

    public function getLimit(): int
    {
        return self::LIMIT;
    }

    /**
     * @param array<int, array{value: mixed, label: mixed}> $options
     */
    private function getOptionLabel(array $options, int|string $value): string
    {
        foreach ($options as $option) {
            if ((string)$option['value'] === (string)$value) {
                return (string)$option['label'];
            }
        }
        return (string)$value;
    }
}
