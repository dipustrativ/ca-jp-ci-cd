<?php

declare(strict_types=1);

namespace Evalent\AdminActivityLog\Model\DiffCheckers;

class Model extends AbstractDiffChecker
{

    /**
     * Ignore there attributes changed due to always being true
     */
    const IGNORE_ATTRIBUTES
        = [
            'updated_at',
            'media_gallery',
            'website_ids',
            'quantity_and_stock_status',
            '_cache_instance_product_set_attributes',
            'stock_data',
            'is_changed_categories',
            'affected_category_ids',
            'force_reindex_eav_required',
            'url_key_create_redirect',
            'save_rewrites_history',
            'is_custom_option_changed',
            'custom_design_from_is_formated',
            'special_from_date_is_formated',
            'news_from_date_is_formated',
            'current_product_id'
        ];

    public function checkDifferences($model): array
    {
        $changedAttributes = [];
        $oldData = $model->getOrigData();
        $newData = $model->getData();
        if ($oldData && $newData) {
            foreach ($newData as $key => $value) {
                if (in_array($key, self::IGNORE_ATTRIBUTES)) {
                    continue;
                }
                if (!key_exists($key, $oldData)) {
                    if ($newData[$key] != "" && $newData[$key] != null) {
                        $changedAttributes[$key] = [
                            'old_data' => "null",
                            'new_data' => $newData[$key],
                        ];
                    }
                    continue;
                }
                if ($oldData[$key] != $newData[$key]) {
                    $changedAttributes[$key] = [
                        'old_data' => $oldData[$key],
                        'new_data' => $newData[$key],
                    ];
                }
            }
            if (sizeof($changedAttributes) < 1) {
                return [];
            }
        }
        return $changedAttributes;
    }
}
