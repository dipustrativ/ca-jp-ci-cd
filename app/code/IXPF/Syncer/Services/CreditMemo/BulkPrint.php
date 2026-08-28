<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\CreditMemo;

use Exception;
use Magento\Sales\Model\Order\Pdf\Creditmemo as CreditmemoPdf;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\CollectionFactory as CreditmemoCollectionFactory;

/**
 * Render a set of credit memos into one combined PDF — mirrors the admin credit
 * memo grid mass action "PDF Credit Memos" (url sales/creditmemo/pdfcreditmemos).
 */
class BulkPrint
{
    public function __construct(
        protected readonly CreditmemoCollectionFactory $creditmemoCollectionFactory,
        protected readonly CreditmemoPdf $creditmemoPdf,
    ) {
    }

    /**
     * @param int[] $creditmemoIds
     * @return array
     * @throws Exception
     */
    public function execute(array $creditmemoIds): array
    {
        $collection = $this->creditmemoCollectionFactory->create()
            ->addFieldToFilter('entity_id', ['in' => $creditmemoIds])
            ->setOrder('entity_id', 'ASC');

        $docs = array_values($collection->getItems());
        if (!$docs) {
            throw new Exception('No credit memos found for the selected ids');
        }

        $pdfContent = $this->creditmemoPdf->getPdf($docs)->render();

        $coveredIds = array_values(array_unique(array_map(
            static fn ($doc) => (int) $doc->getId(),
            $docs
        )));
        $missing = array_values(array_diff($creditmemoIds, $coveredIds));

        return [
            'count'        => count($docs),
            'missing'      => $missing,
            'file_name'    => 'creditmemos_bulk.pdf',
            'file_content' => base64_encode($pdfContent),
            'mime_type'    => 'application/pdf',
        ];
    }
}
