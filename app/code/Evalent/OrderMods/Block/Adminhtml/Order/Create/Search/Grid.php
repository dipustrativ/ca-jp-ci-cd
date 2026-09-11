<?php

declare(strict_types=1);

namespace Evalent\OrderMods\Block\Adminhtml\Order\Create\Search;

use Evalent\OrderMods\Block\Adminhtml\Order\Create\Search\Grid\Renderer\ThumbNail;

class Grid extends \Magento\Sales\Block\Adminhtml\Order\Create\Search\Grid
{

    /**
     * Prepare columns
     *
     * @return \Magento\Sales\Block\Adminhtml\Order\Create\Search\Grid
     * @throws \Exception
     */
    protected function _prepareColumns()
    {
        $this->addColumn(
            'thumbnail',
            [
                'filter' => false,
                'sortable' => false,
                'header' => __('Thumbnail'),
                'index' => 'thumbnail',
                'column_css_class' => 'data-grid-thumbnail-cell',
                'component' => 'Magento_Ui/js/grid/columns/thumbnail',
                'renderer' => ThumbNail::class
            ]
        );

        return parent::_prepareColumns();
    }
}