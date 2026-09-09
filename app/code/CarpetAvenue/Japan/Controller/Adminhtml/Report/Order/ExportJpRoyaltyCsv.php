<?php

declare(strict_types=1);

namespace CarpetAvenue\Japan\Controller\Adminhtml\Report\Order;

use Magento\Backend\Block\Widget\Grid\ExportInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Reports\Controller\Adminhtml\Report\Product;

class ExportJpRoyaltyCsv extends Product
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'Magento_Reports::jp_royalty';

    /**
     * Export Products JP royalty to CSV format action
     *
     * @return ResponseInterface
     * @throws \Exception
     */
    public function execute()
    {
        $this->_view->loadLayout();
        $fileName = 'jp_order_royalty.csv';
        /** @var ExportInterface $exportBlock */
        $exportBlock = $this->_view->getLayout()->getChildBlock('adminhtml.report.grid', 'grid.export');
        return $this->_fileFactory->create(
            $fileName,
            $exportBlock->getCsvFile(),
            DirectoryList::VAR_DIR
        );
    }
}