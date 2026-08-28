<?php

namespace IXPF\Syncer\Ui\Component\Listing\Column;

use IXPF\Syncer\Model\ResourceModel\IxpfPhotoStudioQueue;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\Listing\Columns\Column;

class ProductGalleryWebhookStatus extends Column
{
    /**
     * Prepare component configuration
     *
     * @return void
     * @throws LocalizedException
     */
    public function prepare()
    {
        parent::prepare();
        $this->_data['config']['bodyTmpl'] = 'ui/grid/cells/html';
    }

    /**
     * Prepare Data Source
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$item) {
                if (isset($item['status'])) {
                    $item['status_raw'] = $item['status'];
                    $item['status'] = $this->getStatusHtml($item['status']);
                }
            }
        }
        return $dataSource;
    }

    /**
     * Get status HTML with color
     *
     * @param string $status
     * @return string
     */
    private function getStatusHtml($status)
    {
        $labels = [
            IxpfPhotoStudioQueue::STATUS_PENDING => __('Pending'),
            IxpfPhotoStudioQueue::STATUS_PROCESSING => __('Processing'),
            IxpfPhotoStudioQueue::STATUS_COMPLETED => __('Completed'),
            IxpfPhotoStudioQueue::STATUS_FAILED => __('Failed'),
            IxpfPhotoStudioQueue::STATUS_MAGENTO_FAILED => __('Failed by Magento'),
            IxpfPhotoStudioQueue::STATUS_READY_FOR_WEBHOOK => __('Ready for Webhook'),
            IxpfPhotoStudioQueue::STATUS_WEBHOOK_FAILED => __('Webhook Failed')
        ];
        $colors = [
            IxpfPhotoStudioQueue::STATUS_PENDING => '#808080',
            IxpfPhotoStudioQueue::STATUS_PROCESSING => '#1979c3',
            IxpfPhotoStudioQueue::STATUS_COMPLETED => '#185b00',
            IxpfPhotoStudioQueue::STATUS_FAILED => '#e22626',
            IxpfPhotoStudioQueue::STATUS_MAGENTO_FAILED => '#e22626',
            IxpfPhotoStudioQueue::STATUS_READY_FOR_WEBHOOK => '#1979c3',
            IxpfPhotoStudioQueue::STATUS_WEBHOOK_FAILED => '#e22626'
        ];


        $color = $colors[$status] ?? '#000000';
        $status = $labels[$status] ?? $status;
        
        return sprintf(
            '<span class="grid-severity-notice" style="background: %s; color: #fff; border-color: transparent"><span>%s</span></span>',
            $color,
            $status
        );
    }
}