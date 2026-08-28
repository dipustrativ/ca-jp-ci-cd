<?php

declare(strict_types=1);

namespace Evalent\Banner\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class Enabled extends Column
{
    const NAME = 'enabled';

    /**
     * Prepare Data Source
     *
     * @param array $dataSource
     *
     * @return array
     * @throws \Exception
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as & $item) {
                $item[self::NAME] = $item[self::NAME] == 1 ? __('Yes') : __('No');
            }
        }

        return $dataSource;
    }
}