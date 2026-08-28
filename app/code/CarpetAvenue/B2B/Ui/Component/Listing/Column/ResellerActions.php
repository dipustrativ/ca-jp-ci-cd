<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * "View" action for the reseller applications grid.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class ResellerActions extends Column
{
    private const VIEW_URL = 'carpetavenue_b2b/reseller/view';

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
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

        $name = $this->getData('name');
        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item['application_id'])) {
                continue;
            }

            $item[$name]['view'] = [
                'href' => $this->urlBuilder->getUrl(self::VIEW_URL, ['id' => $item['application_id']]),
                'label' => __('View'),
                'hidden' => false,
            ];
        }

        return $dataSource;
    }
}
