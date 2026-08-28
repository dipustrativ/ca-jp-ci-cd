<?php
declare(strict_types=1);

namespace IXPF\Syncer\Model\Attribute;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as ProductAttributeCollectionFactory;
use Magento\Eav\Api\AttributeOptionManagementInterface;
use Magento\Eav\Api\AttributeSetRepositoryInterface;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Group\CollectionFactory as GroupCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\App\Emulation;
use Magento\Framework\Api\SearchCriteriaBuilder;

class AttributeSetExplorer
{
    private const ENTITY_TYPE = Product::ENTITY;

    public function __construct(
        private readonly AttributeSetRepositoryInterface $attributeSetRepository,
        private readonly GroupCollectionFactory $groupCollectionFactory,
        private readonly ProductAttributeCollectionFactory $productAttributeCollectionFactory,
        private readonly AttributeOptionManagementInterface $attributeOptionManagement,
        private readonly ResourceConnection $resource,
        private readonly Emulation $emulation,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {}

    /**
     * @param string|null $setIds  of attribute_set_id (e.g. "4,10")
     * @param bool $includeDisabled Include non-visible attributes
     * @param int|null $storeId Emulate store for localized labels
     * @param bool $includeSwatch Include swatch metadata if available
     * @return array
     */
    public function getProductAttributeSets(
        ?string $setIds = null,
        bool $includeDisabled = false,
        ?int $storeId = null,
        bool $includeSwatch = false
    ): array {
        $stopEmulation = false;
        try {
            if ($storeId !== null) {
                $this->emulation->startEnvironmentEmulation($storeId, 'frontend', true);
                $stopEmulation = true;
            }

            $setIds = $this->resolveTargetSetIds($setIds);
            $payload = [];
            foreach ($setIds as $setId) {
                // Skip invalids gracefully
                try {
                    $set = $this->attributeSetRepository->get((int)$setId);
                } catch (\Throwable) {
                    continue;
                }

                $groups = $this->fetchGroups((int)$setId);
                $groupPayloads = [];

                foreach ($groups as $group) {
                    $attrs = $this->fetchAttributesForGroup((int)$setId, (int)$group->getId(), $includeDisabled);
                    $attrPayloads = [];

                    foreach ($attrs as $attr) {
                        $frontendInput = (string)$attr->getFrontendInput();
                        $options = [];

                        if (in_array($frontendInput, ['select', 'multiselect'], true)) {
                            try {
                                $optItems = $this->attributeOptionManagement->getItems(
                                    self::ENTITY_TYPE,
                                    (string)$attr->getAttributeCode()
                                );
                                foreach ($optItems as $opt) {
                                    $optRow = [
                                        'value' => (string)$opt->getValue(),
                                        'label' => (string)$opt->getLabel()
                                    ];
                                    $options[] = $optRow;
                                }

                                if ($includeSwatch) {
                                    $this->enrichOptionsWithSwatch(
                                        (int)$attr->getAttributeId(),
                                        $options
                                    );
                                }
                            } catch (\Throwable) {
                                // Fallback: source model
                                $source = $attr->getSource();
                                if ($source) {
                                    foreach ($source->getAllOptions(false) as $o) {
                                        $options[] = [
                                            'value' => (string)$o['value'],
                                            'label' => (string)$o['label']
                                        ];
                                    }
                                    if ($includeSwatch) {
                                        $this->enrichOptionsWithSwatch(
                                            (int)$attr->getAttributeId(),
                                            $options
                                        );
                                    }
                                }
                            }
                        }

                        $attrPayloads[] = [
                            'attribute_id'    => (int)$attr->getAttributeId(),
                            'code'            => (string)$attr->getAttributeCode(),
                            'label'           => (string)$attr->getDefaultFrontendLabel(),
                            'frontend_input'  => $frontendInput,
                            'required'        => (bool)$attr->getIsRequired(),
                            'is_visible'      => (bool)$attr->getIsVisible(),
                            'is_user_defined' => (bool)$attr->getIsUserDefined(),
                            'is_unique'       => (bool)$attr->getIsUnique(),
                            'scope'           => $this->scopeLabel((int)$attr->getIsGlobal()),
                            'note'            => (string)$attr->getNote(),
                            'position'        => (int)$attr->getPosition(),
                            'default_value'   => $attr->getDefaultValue(),
                            'options'         => $options
                        ];
                    }

                    $groupPayloads[] = [
                        'group_id'   => (int)$group->getId(),
                        'group_name' => (string)$group->getAttributeGroupName(),
                        'sort_order' => (int)$group->getSortOrder(),
                        'attributes' => $attrPayloads
                    ];
                }

                $payload[] = [
                    'set_id'   => (int)$set->getAttributeSetId(),
                    'entity_type_id' => (int)$set->getEntityTypeId(),
                    'set_name' => (string)$set->getAttributeSetName(),
                    'groups'   => $groupPayloads
                ];
            }

            return $payload;
        } finally {
            if ($stopEmulation) {
                $this->emulation->stopEnvironmentEmulation();
            }
        }
    }


    /** @return int[] */
    private function resolveTargetSetIds(?string $setIds): array
    {
        if ($setIds) {
            $ids = array_filter(array_map('trim', explode(',', $setIds)), static fn($v) => $v !== '');
            return array_values(array_unique(array_map('intval', $ids)));
        }

        $searchCriteria = $this->searchCriteriaBuilder->create();
        /*$searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('entity_type_id', 4)
            ->create();*/
        $searchResult = $this->attributeSetRepository->getList($searchCriteria);
        $ids = [];

        foreach ($searchResult->getItems() as $set) {
            $ids[] = (int)$set->getAttributeSetId();
        }

        return $ids;
    }

    private function fetchGroups(int $setId): array
    {
        $collection = $this->groupCollectionFactory->create();
        $collection->addFieldToFilter('attribute_set_id', $setId);
        $collection->setOrder('sort_order', 'ASC');
        return $collection->getItems();
    }

    private function fetchAttributesForGroup(int $setId, int $groupId, bool $includeDisabled): array
    {
        $collection = $this->productAttributeCollectionFactory->create();
        $collection->setAttributeSetFilter($setId);
        $collection->join(
            ['eea' => $collection->getTable('eav_entity_attribute')],
            'main_table.attribute_id = eea.attribute_id',
            ['eea.attribute_group_id', 'eea.sort_order']
        );
        $collection->addFieldToFilter('eea.attribute_group_id', $groupId);
        $collection->setOrder('eea.sort_order', 'ASC');

        if (!$includeDisabled) {
            $collection->addFieldToFilter('is_visible', 1);
        }

        return $collection->getItems();
    }

    private function scopeLabel(int $isGlobal): string
    {
        return match ($isGlobal) {
            0 => 'store',
            2 => 'website',
            default => 'global',
        };
    }

    /**
     * Enrich options array (by ref) with swatch data if Magento_Swatches is present.
     * Input $options format: [['value' => '12', 'label' => 'Red'], ...]
     */
    private function enrichOptionsWithSwatch(int $attributeId, array &$options): void
    {
        if (empty($options)) {
            return;
        }

        $conn = $this->resource->getConnection();
        $optIds = array_values(array_filter(array_map(static function ($o) {
            // ignore "0" / placeholder and non-numeric values
            return ctype_digit((string)($o['value'] ?? '')) ? (int)$o['value'] : null;
        }, $options), static fn($v) => $v !== null));

        if (!$optIds) {
            return;
        }

        $optTable    = $this->resource->getTableName('eav_attribute_option');
        $swatchTable = $this->resource->getTableName('eav_attribute_option_swatch');

        // Pull swatch rows for those option_ids (type: 1=text, 2=visual; value holds text or hex/image)
        $select = $conn->select()
            ->from(['o' => $optTable], ['option_id'])
            ->joinLeft(
                ['s' => $swatchTable],
                'o.option_id = s.option_id',
                ['type', 'value'] // value = hex (for visual) or text (for text)
            )
            ->where('o.attribute_id = ?', $attributeId)
            ->where('o.option_id IN (?)', $optIds);

        $rows = $conn->fetchAll($select);
        if (!$rows) {
            return;
        }

        // Map swatches by option_id
        $byId = [];
        foreach ($rows as $r) {
            $id = (int)$r['option_id'];
            // Prefer a single best swatch row (store_id=0 is stored in the table; multiple rows may exist)
            if (!isset($byId[$id])) {
                $byId[$id] = [
                    'type'  => isset($r['type']) ? (int)$r['type'] : null,
                    'value' => $r['value'] ?? null,
                ];
            }
        }

        // Merge into $options
        foreach ($options as &$opt) {
            $id = ctype_digit((string)($opt['value'] ?? '')) ? (int)$opt['value'] : 0;
            if ($id && isset($byId[$id])) {
                $sw = $byId[$id];
                $opt['swatch'] = [
                    'type'  => $sw['type'],   // 1=text, 2=visual
                    'value' => $sw['value'],  // text or hex/image
                ];
            }
        }
        unset($opt);
    }
}
