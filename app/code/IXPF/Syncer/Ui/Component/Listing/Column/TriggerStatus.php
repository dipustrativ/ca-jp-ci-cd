<?php

namespace IXPF\Syncer\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

class TriggerStatus extends Column
{
    /**
     * Prepare component configuration
     *
     * @return void
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
                if (isset($item['trigger_status'])) {
                    $item['trigger_status_raw'] = $item['trigger_status'];
                    $item['trigger_status'] = $this->getStatusHtml($item['trigger_status']);
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
        $colors = [
            'pending' => '#808080',
            'processing' => '#1979c3',
            'failed' => '#e22626',
            'success' => '#185b00'
        ];

        $labels = [
            'pending' => __('Pending'),
            'processing' => __('Processing'),
            'failed' => __('Failed'),
            'success' => __('Success')
        ];

        $color = $colors[$status] ?? '#000000';
        $label = $labels[$status] ?? $status;

        return sprintf(
            '<span class="grid-severity-notice" style="background: %s; color: #fff; border-color: transparent"><span>%s</span></span>',
            $color,
            $label
        );
    }
}