<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Shipment;

use Exception;
use Magento\Sales\Model\Order\Pdf\Shipment as ShipmentPdf;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;
use Magento\Shipping\Model\Shipping\LabelGenerator;

/**
 * Render selected shipments — mirrors the admin shipment grid mass actions:
 *   - "PDF Shipments"        → packing-slip PDF (core ShipmentPdf renderer)
 *   - "Print Shipping Labels" → combined carrier-label PDF (LabelGenerator)
 */
class BulkPrint
{
    public function __construct(
        protected readonly ShipmentCollectionFactory $shipmentCollectionFactory,
        protected readonly ShipmentPdf $shipmentPdf,
        protected readonly LabelGenerator $labelGenerator,
    ) {
    }

    /**
     * Packing-slip PDF across all selected shipments — admin "PDF Shipments".
     *
     * @param int[] $shipmentIds
     * @return array
     * @throws Exception
     */
    public function printPackingSlips(array $shipmentIds): array
    {
        $docs = $this->loadShipments($shipmentIds);
        if (!$docs) {
            throw new Exception('No shipments found for the selected ids');
        }

        $pdfContent = $this->shipmentPdf->getPdf($docs)->render();

        return [
            'count'        => count($docs),
            'missing'      => $this->missingIds($shipmentIds, $docs),
            'file_name'    => 'shipments_bulk.pdf',
            'file_content' => base64_encode($pdfContent),
            'mime_type'    => 'application/pdf',
        ];
    }

    /**
     * Combined carrier-label PDF — admin "Print Shipping Labels". Only shipments
     * that actually carry a generated label contribute; the rest are reported as
     * missing.
     *
     * @param int[] $shipmentIds
     * @return array
     * @throws Exception
     */
    public function printShippingLabels(array $shipmentIds): array
    {
        $docs = $this->loadShipments($shipmentIds);
        if (!$docs) {
            throw new Exception('No shipments found for the selected ids');
        }

        $labelsContent = [];
        $withLabel = [];
        foreach ($docs as $shipment) {
            $label = $shipment->getShippingLabel();
            if ($label) {
                $labelsContent[] = $label;
                $withLabel[] = (int) $shipment->getId();
            }
        }

        if (!$labelsContent) {
            throw new Exception('No shipping labels found for the selected shipments');
        }

        $pdfContent = $this->labelGenerator->combineLabelsPdf($labelsContent)->render();

        return [
            'count'        => count($labelsContent),
            'missing'      => array_values(array_diff($shipmentIds, $withLabel)),
            'file_name'    => 'shipping_labels_bulk.pdf',
            'file_content' => base64_encode($pdfContent),
            'mime_type'    => 'application/pdf',
        ];
    }

    /**
     * @param int[] $shipmentIds
     * @return object[]
     */
    private function loadShipments(array $shipmentIds): array
    {
        $collection = $this->shipmentCollectionFactory->create()
            ->addFieldToFilter('entity_id', ['in' => $shipmentIds])
            ->setOrder('entity_id', 'ASC');

        return array_values($collection->getItems());
    }

    /**
     * @param int[]    $requestedIds
     * @param object[] $docs
     * @return int[]
     */
    private function missingIds(array $requestedIds, array $docs): array
    {
        $coveredIds = array_values(array_unique(array_map(
            static fn ($doc) => (int) $doc->getId(),
            $docs
        )));
        return array_values(array_diff($requestedIds, $coveredIds));
    }
}
