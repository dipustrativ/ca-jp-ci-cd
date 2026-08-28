<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Controller\Adminhtml\AttributeOverride;

use CarpetAvenue\Utilities\Service\AttributeOverride\FixService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * POST endpoint: apply fix.
 * Workflow: backup CSV → apply intended values → clear cache.
 * Returns JSON: { success, processed, fixed, errors, csv_path, csv_filename, message }
 */
class RunFix extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_Utilities::attribute_override';

    public function __construct(
        Context $context,
        private readonly FixService $fixService,
        private readonly JsonFactory $jsonFactory,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $params = $this->parseParams();
            $this->validate($params);

            $fixResult = $this->fixService->fix(
                $params['type'],
                $params['attribute'],
                $params['image_type'],
                $params['scope'],
                $params['scope_values'],
                $params['store_id'],
                false,  // with backup
                false   // not dry-run
            );

            $csvFilename = $fixResult['csv_path'] !== '' ? basename($fixResult['csv_path']) : '';

            $result->setData([
                'success'      => true,
                'processed'    => $fixResult['processed'],
                'fixed'        => $fixResult['fixed'],
                'errors'       => $fixResult['errors'],
                'csv_path'     => $fixResult['csv_path'],
                'csv_filename' => $csvFilename,
                'message'      => __(
                    'Fix complete. Processed: %1 | Fixed: %2 | Errors: %3.',
                    $fixResult['processed'],
                    $fixResult['fixed'],
                    $fixResult['errors']
                ),
            ]);

        } catch (\InvalidArgumentException $e) {
            $result->setData(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Exception $e) {
            $result->setData(['success' => false, 'message' => __('Fix failed: %1', $e->getMessage())]);
        }

        return $result;
    }

    private function parseParams(): array
    {
        $scopeValuesRaw = trim($this->getRequest()->getParam('scope_values', ''));

        return [
            'type'         => trim((string) $this->getRequest()->getParam('type', '')),
            'attribute'    => trim((string) $this->getRequest()->getParam('attribute', '')),
            'image_type'   => trim((string) $this->getRequest()->getParam('image_type', 'all')) ?: 'all',
            'scope'        => trim((string) $this->getRequest()->getParam('scope', 'all')) ?: 'all',
            'scope_values' => $scopeValuesRaw !== ''
                ? array_filter(array_map('trim', preg_split('/[\s,]+/', $scopeValuesRaw)))
                : [],
            'store_id'     => (int) $this->getRequest()->getParam('store_id', 0),
        ];
    }

    private function validate(array $p): void
    {
        if (!in_array($p['type'], ['price', 'image', 'custom'], true)) {
            throw new \InvalidArgumentException((string) __('Invalid type. Must be price, image, or custom.'));
        }
        if ($p['type'] !== 'image' && $p['attribute'] === '') {
            throw new \InvalidArgumentException((string) __('Attribute code is required for type "%1".', $p['type']));
        }
        if ($p['store_id'] <= 0) {
            throw new \InvalidArgumentException((string) __('A valid store view must be selected.'));
        }
        if ($p['scope'] !== 'all' && empty($p['scope_values'])) {
            throw new \InvalidArgumentException((string) __('SKUs or IDs are required when scope is "%1".', $p['scope']));
        }
    }
}
