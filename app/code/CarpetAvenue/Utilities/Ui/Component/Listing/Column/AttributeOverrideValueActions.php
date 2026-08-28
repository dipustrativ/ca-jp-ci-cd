<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column as MagentoColumn;

class AttributeOverrideValueActions extends MagentoColumn
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        protected UrlInterface $urlBuilder,
        protected Escaper $escaper,
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

        foreach ($dataSource['data']['items'] as &$item) {
            if (!isset($item['id'])) {
                continue;
            }

            $item[$this->getData('name')] = [
                'delete' => [
                    'href'    => $this->urlBuilder->getUrl('utilities/attributeoverride_values/delete', ['id' => (int) $item['id']]),
                    'label'   => __('Delete'),
                    'confirm' => [
                        'title'   => __('Delete Intended Value'),
                        'message' => __('Delete this intended value record? Detect will skip this product/attribute until re-snapshotted.'),
                    ],
                    'post' => true,
                ],
            ];
        }

        return $dataSource;
    }
}
