<?php
declare(strict_types=1);

namespace IXPF\Syncer\Ui\Component\Listing\Column;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class BulkUpdateQueueStatus extends Column
{
    private const STATUS_LABELS = [
        'pending' => 'Pending',
        'processing' => 'Processing',
        'completed' => 'Completed',
        'failed' => 'Failed',
    ];

    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as &$item) {
                if (isset($item['status'])) {
                    $status = strtolower($item['status']);
                    $label = self::STATUS_LABELS[$status] ?? ucfirst($status);

                    // Render HTML with styling
                    $item[$this->getData('name')] = sprintf(
                        '<span class="grid-severity-%s" style="font-weight: bold;">%s</span>',
                        $status,
                        $label
                    );
                }
            }
        }
        return $dataSource;
    }
}

