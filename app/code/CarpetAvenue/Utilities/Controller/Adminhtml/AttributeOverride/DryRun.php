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
 * AJAX endpoint: dry-run preview.
 * Returns JSON: { success, preview: [{entity_id, sku, store_id, attribute_code, current_value, intended_value}], message }
 * No DB writes, no backup.
 */
class DryRun extends Action implements HttpPostActionInterface
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
                true,   // no-backup
                true    // dry-run
            );

            $result->setData([
                'success' => true,
                'preview' => $fixResult['preview'],
                'total'   => count($fixResult['preview']),
                'message' => count($fixResult['preview']) === 0
                    ? __('No changes needed — all values already match intended.')
                    : __('%1 row(s) would be changed.', count($fixResult['preview'])),
            ]);

        } catch (\InvalidArgumentException $e) {
            $result->setData(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Exception $e) {
            $result->setData(['success' => false, 'message' => __('Dry-run failed: %1', $e->getMessage())]);
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
