<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Controller\Adminhtml\Icon;

use CarpetAvenue\Orderify\Model\PaymentIcon\SheetImporter;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Admin "Update Payment Icons" button endpoint: runs the sheet import.
 */
class Update extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_Orderify::payment_icons';

    /** @var JsonFactory */
    private $jsonFactory;

    /** @var SheetImporter */
    private $importer;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        SheetImporter $importer
    ) {
        parent::__construct($context);
        $this->jsonFactory = $jsonFactory;
        $this->importer = $importer;
    }

    /**
     * @return ResultInterface
     */
    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $summary = $this->importer->import(false);
        } catch (\Throwable $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }

        $failures = $summary['failures'] ?? [];
        $message = sprintf(
            '%d store(s) updated, %d unchanged, %d disabled, %d failed. %d icon(s) written.',
            $summary['updated_stores'],
            $summary['unchanged_stores'],
            count($summary['disabled']),
            count($failures),
            $summary['written_icons']
        );

        if (!$summary['updated_stores'] && !$failures) {
            $message = 'Nothing changed — all store views already up to date. ' . $message;
        }

        if ($failures) {
            $details = [];
            foreach (array_slice($failures, 0, 10) as $f) {
                $details[] = sprintf('row %d: %s/%s — %s', $f['row'], $f['store'], $f['method'], $f['reason']);
            }
            $message .= sprintf(
                ' ⚠ Failed rows: %s. See admin Notifications and var/log/carpetavenue/orderify/payment_icons_%s.log',
                implode('; ', $details),
                date('Y-m-d')
            );
        }

        return $result->setData([
            'success' => true,
            'has_failures' => !empty($failures),
            'message' => $message,
            'summary' => $summary,
        ]);
    }
}
