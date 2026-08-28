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

use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Truncates cron_schedule.messages for display.
 *
 * That column is TEXT and holds whole stack traces. Shipping it untouched would put
 * megabytes of JSON on the wire for a single page of results and wreck the grid
 * layout, so it is cut server-side with the full text kept in a title attribute.
 */
class Message extends Column
{
    /**
     * Characters kept before truncation
     */
    private const MAX_LENGTH = 200;

    /**
     * Constructor
     *
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param Escaper $escaper
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private Escaper $escaper,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Truncate every message, keeping the full text as a tooltip
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

        foreach ($dataSource['data']['items'] as &$item) {
            $message = trim((string) ($item[$name] ?? ''));
            if ($message === '') {
                $item[$name] = '';
                continue;
            }

            $short = $message;
            if (mb_strlen($message) > self::MAX_LENGTH) {
                $short = mb_substr($message, 0, self::MAX_LENGTH) . '…';
            }

            $item[$name] = sprintf(
                '<span class="developify-cron-message" title="%s">%s</span>',
                // Tooltip is capped too — a 2MB title attribute helps nobody.
                $this->escaper->escapeHtmlAttr(mb_substr($message, 0, 2000)),
                $this->escaper->escapeHtml($short)
            );
        }

        return $dataSource;
    }
}
