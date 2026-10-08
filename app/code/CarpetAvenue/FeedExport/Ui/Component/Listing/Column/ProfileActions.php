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

namespace CarpetAvenue\FeedExport\Ui\Component\Listing\Column;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class ProfileActions extends Column
{
    /**
     * @param array<string, mixed> $components
     * @param array<string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        private readonly AuthorizationInterface $authorization,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        $canRun = $this->authorization->isAllowed('CarpetAvenue_FeedExport::run');
        foreach ($dataSource['data']['items'] as &$item) {
            $profileId = $item['profile_id'] ?? null;
            if ($profileId === null) {
                continue;
            }
            $actions = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl('carpetavenue_feedexport/profile/edit', ['profile_id' => $profileId]),
                    'label' => __('Edit'),
                ],
            ];
            if ($canRun) {
                $actions['run'] = [
                    'href' => $this->urlBuilder->getUrl('carpetavenue_feedexport/profile/run', ['profile_id' => $profileId]),
                    'label' => __('Run Export Now'),
                    'confirm' => [
                        'title' => __('Run Export'),
                        'message' => __('Generate feed for profile #%1 now? Large feeds can take minutes.', $profileId),
                    ],
                ];
            }
            $actions['delete'] = [
                'href' => $this->urlBuilder->getUrl('carpetavenue_feedexport/profile/delete', ['profile_id' => $profileId]),
                'label' => __('Delete'),
                'confirm' => [
                    'title' => __('Delete Feed Profile'),
                    'message' => __('Delete feed profile #%1? Generated files stay on disk.', $profileId),
                ],
                'post' => true,
            ];
            $item[$this->getData('name')] = $actions;
        }
        return $dataSource;
    }
}
