<?php

declare(strict_types=1);

namespace IXPF\Syncer\Ui\Component\Listing\Column;

use IXPF\Syncer\Model\IxpfReservation;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Colours the delivery status so a failed reservation is obvious at a glance —
 * that is the only place an unsent reservation ever surfaces.
 */
class ReservationStatus extends Column
{
    private const COLORS = [
        IxpfReservation::REQUEST_PENDING => '#808080',
        IxpfReservation::REQUEST_PROCESSING => '#1979c3',
        IxpfReservation::REQUEST_SUCCESS => '#185b00',
        IxpfReservation::REQUEST_APPLIED => '#185b00',
        IxpfReservation::REQUEST_FAILED => '#e22626',
        // Amber: not an error to chase, but nothing was sent either.
        IxpfReservation::REQUEST_SKIPPED => '#e07b00',
    ];

    private const LABELS = [
        IxpfReservation::REQUEST_PENDING => 'Pending',
        IxpfReservation::REQUEST_PROCESSING => 'Processing',
        IxpfReservation::REQUEST_SUCCESS => 'Success',
        IxpfReservation::REQUEST_APPLIED => 'Applied',
        IxpfReservation::REQUEST_FAILED => 'Failed',
        IxpfReservation::REQUEST_SKIPPED => 'Skipped',
    ];

    public function prepare(): void
    {
        parent::prepare();
        $this->_data['config']['bodyTmpl'] = 'ui/grid/cells/html';
    }

    /**
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            if (isset($item['request_status'])) {
                $item['request_status_raw'] = $item['request_status'];
                $item['request_status'] = $this->getStatusHtml((string)$item['request_status']);
            }
        }

        return $dataSource;
    }

    private function getStatusHtml(string $status): string
    {
        $color = self::COLORS[$status] ?? '#000000';
        $label = isset(self::LABELS[$status]) ? __(self::LABELS[$status]) : $status;

        return sprintf(
            '<span class="grid-severity-notice" style="background: %s; color: #fff; border-color: transparent"><span>%s</span></span>',
            $color,
            $label
        );
    }
}
