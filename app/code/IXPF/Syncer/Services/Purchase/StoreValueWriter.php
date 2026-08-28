<?php

namespace IXPF\Syncer\Services\Purchase;

use IXPF\Syncer\Helper\Logger\PurchaseLogger;
use Magento\Framework\App\ResourceConnection;

/**
 * Writes per-store-view (translation) attribute values via raw SQL, bypassing
 * the ORM so bulk product creation stays fast.
 *
 * Two paths, chosen by the attribute's frontend_input:
 *  - text / textarea            -> catalog_product_entity_{varchar|text}
 *                                  one value row per product per store view.
 *  - select / multiselect       -> eav_attribute_option_value
 *                                  a translated LABEL row per option per store view.
 *                                  The product itself always stores the (global)
 *                                  option id from the base value; only the option
 *                                  label is translated. Option ids are resolved
 *                                  positionally from the base (store-0) value, so
 *                                  the base and translated comma lists MUST line up
 *                                  in order and count.
 *
 * NOTE: raw writes bypass Magento indexers. After a bulk run reindex
 * catalogsearch_fulltext + catalog_product_attribute and flush cache.
 */
class StoreValueWriter
{
    /**
     * Keys allowed inside a store_values entry that are NOT store-view-scoped EAV
     * attributes — store identifiers plus global/structural fields (category and
     * website assignment have no store scope in Magento). Silently skipped.
     */
    private const NON_ATTRIBUTE_KEYS = [
        'store_id',
        'store_code',
        'categories',
        'category_ids',
        'website_ids',
        'store_ids',
        'sku',
    ];

    /** @var array<string,int|null> store code => store view id (null = unresolved) */
    private array $storeIdCache = [];

    public function __construct(
        protected ResourceConnection $resourceConnection,
        protected ProductAttribute   $productAttribute,
        protected PurchaseLogger      $logger
    ) {
    }

    /**
     * @param int   $entityId    product entity id (already saved at store 0)
     * @param array $baseProduct original product payload (base labels, for option pairing)
     * @param array $storeValues list of per-store override entries
     * @return void
     */
    public function applyStoreValues(int $entityId, array $baseProduct, array $storeValues): void
    {
        if (empty($storeValues)) {
            return;
        }

        foreach ($storeValues as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $storeId = $this->resolveStoreId($entry);
            if ($storeId === null) {
                continue; // resolveStoreId() has logged the reason
            }

            foreach ($entry as $attrCode => $translatedValue) {
                // Store keys + structural fields that are not store-view scoped EAV
                // attributes. category/website assignment is global in Magento
                // (no store_id column), so it is silently ignored here.
                if (in_array($attrCode, self::NON_ATTRIBUTE_KEYS, true)) {
                    continue;
                }

                try {
                    $this->writeAttribute(
                        $entityId,
                        $storeId,
                        (string)$attrCode,
                        $translatedValue,
                        $baseProduct[$attrCode] ?? null
                    );
                } catch (\Throwable $e) {
                    $this->logger->writeDaily(
                        "[store_values] entity {$entityId} store {$storeId} attr {$attrCode} failed: "
                        . $e->getMessage(),
                        'error'
                    );
                }
            }
        }
    }

    /**
     * @param int $entityId
     * @param int $storeId
     * @param string $attrCode
     * @param mixed $translatedValue
     * @param mixed $baseValue
     * @return void
     */
    private function writeAttribute(
        int    $entityId,
        int    $storeId,
        string $attrCode,
        mixed  $translatedValue,
        mixed  $baseValue
    ): void {
        $attribute = $this->productAttribute->getAttribute($attrCode);
        if (!$attribute) {
            $this->logger->writeDaily("[store_values] unknown attribute '{$attrCode}', skipped", 'error');
            return;
        }

        $inputType = (string)$attribute->getData('frontend_input');

        if (in_array($inputType, ['select', 'multiselect'], true)) {
            $this->writeOptionLabels($attrCode, $baseValue, $translatedValue, $storeId);
            return;
        }

        // text / textarea -> per-product value row, table picked by backend_type
        $backendType = (string)$attribute->getData('backend_type');
        if (!in_array($backendType, ['varchar', 'text'], true)) {
            // int/decimal/datetime are not translatable text – ignore
            return;
        }

        $this->writeProductValue(
            (int)$attribute->getData('attribute_id'),
            $backendType,
            $entityId,
            $storeId,
            $translatedValue === null ? null : (string)$translatedValue
        );
    }

    /**
     * @param int $attributeId
     * @param string $backendType
     * @param int $entityId
     * @param int $storeId
     * @param string|null $value
     * @return void
     */
    private function writeProductValue(
        int     $attributeId,
        string  $backendType,
        int     $entityId,
        int     $storeId,
        ?string $value
    ): void {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_product_entity_' . $backendType);

        // unique key: (entity_id, attribute_id, store_id)
        $connection->insertOnDuplicate(
            $table,
            [
                'attribute_id' => $attributeId,
                'store_id'     => $storeId,
                'entity_id'    => $entityId,
                'value'        => $value,
            ],
            ['value']
        );
    }

    /**
     * Translate select/multiselect option LABELS for a store view. Option ids are
     * resolved from the base (store-0) labels and paired positionally with the
     * translated labels; counts must match or the whole attribute is skipped.
     *
     * @param string $attrCode
     * @param mixed $baseValue
     * @param mixed $translatedValue
     * @param int $storeId
     * @return void
     */
    private function writeOptionLabels(string $attrCode, mixed $baseValue, mixed $translatedValue, int $storeId): void
    {
        if ($baseValue === null || $baseValue === '') {
            $this->logger->writeDaily(
                "[store_values] attr '{$attrCode}' store {$storeId}: no base value to pair option translation, skipped",
                'error'
            );
            return;
        }

        $baseLabels = $this->splitLabels($baseValue);
        $transLabels = $this->splitLabels($translatedValue);

        if (count($baseLabels) !== count($transLabels)) {
            $this->logger->writeDaily(
                "[store_values] attr '{$attrCode}' store {$storeId}: option count mismatch (base "
                . count($baseLabels) . " vs translation " . count($transLabels) . "), skipped",
                'error'
            );
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('eav_attribute_option_value');

        foreach ($baseLabels as $i => $baseLabel) {
            // Option id from the BASE label; creates the option (store-0 label) if missing.
            $optionId = $this->productAttribute->getAttributeOptionValueFromLabel($attrCode, $baseLabel);
            if (!$optionId) {
                $this->logger->writeDaily(
                    "[store_values] attr '{$attrCode}' store {$storeId}: option id not found for base label '{$baseLabel}', skipped",
                    'error'
                );
                continue;
            }

            // unique key: (store_id, option_id)
            $connection->insertOnDuplicate(
                $table,
                [
                    'option_id' => (int)$optionId,
                    'store_id'  => $storeId,
                    'value'     => $transLabels[$i],
                ],
                ['value']
            );
        }
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function splitLabels(mixed $value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } else {
            $parts = explode(',', (string)$value);
        }

        return array_values(array_filter(
            array_map('trim', $parts),
            static fn($v) => $v !== ''
        ));
    }

    /**
     * Prefer numeric store_id; fall back to store_code lookup. store 0 (admin) and
     * unknown codes return null (base value already covers the default scope).
     *
     * @param array $entry
     * @return int|null
     */
    private function resolveStoreId(array $entry): ?int
    {
        if (isset($entry['store_id']) && $entry['store_id'] !== '') {
            $id = (int)$entry['store_id'];
            return $id > 0 ? $id : null;
        }

        $code = isset($entry['store_code']) ? trim((string)$entry['store_code']) : '';
        if ($code === '') {
            $this->logger->writeDaily('[store_values] entry missing store_id/store_code, skipped', 'error');
            return null;
        }

        if (array_key_exists($code, $this->storeIdCache)) {
            return $this->storeIdCache[$code];
        }

        $connection = $this->resourceConnection->getConnection();
        $storeId = $connection->fetchOne(
            $connection->select()
                ->from($this->resourceConnection->getTableName('store'), 'store_id')
                ->where('code = ?', $code)
                ->limit(1)
        );

        $resolved = ($storeId !== false && (int)$storeId > 0) ? (int)$storeId : null;
        if ($resolved === null) {
            $this->logger->writeDaily("[store_values] unknown store_code '{$code}', skipped", 'error');
        }

        $this->storeIdCache[$code] = $resolved;
        return $resolved;
    }
}
