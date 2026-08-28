<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Developify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Developify\Ui\Component\Listing\Column;

use CarpetAvenue\Developify\Model\Config\Source\CronStatus as StatusSource;
use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Renders cron_schedule.status as a coloured pill.
 *
 * The column declares bodyTmpl ui/grid/cells/html so the markup below is rendered
 * rather than escaped.
 */
class CronStatus extends Column
{
    /**
     * Constructor
     *
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param Escaper $escaper
     * @param StatusSource $statusSource
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private Escaper $escaper,
        private StatusSource $statusSource,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Wrap each status value in a badge
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        $name = $this->getData('name');
        $labels = $this->statusSource->getLabels();

        foreach ($dataSource['data']['items'] as &$item) {
            $status = (string) ($item[$name] ?? '');
            if ($status === '') {
                continue;
            }

            $label = isset($labels[$status]) ? (string) $labels[$status] : $status;

            $item[$name] = sprintf(
                '<span class="developify-cron-badge developify-cron-badge-%s">%s</span>',
                $this->escaper->escapeHtmlAttr($status),
                $this->escaper->escapeHtml($label)
            );
        }

        return $dataSource;
    }
}
