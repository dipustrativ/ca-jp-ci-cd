<?php

declare(strict_types=1);

namespace Evalent\ProductCertificate\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Seeds the Certificate of Authenticity (COA) configuration and guarantees the
 * `production_type` attribute carries the full set of production-type options.
 *
 * Config (default scope):
 *   sales_pdf/coa/production_type     = option ids of the 8 COA-eligible types
 *   sales_pdf/coa/row_price_limit     = 0
 *   sales_pdf/coa/include_signature   = 0
 *   sales_pdf/coa/footer_text         = <JP store address>
 *   sales_pdf/coa/attach_to_shipment  = 0
 */
class ConfigureCoaDefaults implements DataPatchInterface
{
    private const ATTRIBUTE_CODE = 'production_type';

    /**
     * Every production type that must exist as an attribute option.
     */
    private const PRODUCTION_TYPES = [
        'Handknotted',
        'Handloom',
        'Handwoven',
        'Machine knotted',
        'Handtufted',
        'Machine knotted & Hand-finished',
        'Loomknotted',
        'Hand & Machine',
        'Printed',
    ];

    /**
     * Types selected in the COA "Generate for production types" setting.
     * Everything except "Printed".
     */
    private const COA_SELECTED_TYPES = [
        'Handknotted',
        'Handloom',
        'Handwoven',
        'Machine knotted',
        'Handtufted',
        'Machine knotted & Hand-finished',
        'Loomknotted',
        'Hand & Machine',
    ];

    private const FOOTER_TEXT =
        '株式会社 Carpet Avenue Japan   〒248-0022 神奈川県鎌倉市常盤362-4(店舗)';

    private EavSetup $eavSetup;

    public function __construct(
        private ModuleDataSetupInterface $moduleDataSetup,
        EavSetupFactory $eavSetupFactory,
        private WriterInterface $configWriter
    ) {
        $this->eavSetup = $eavSetupFactory->create(['setup' => $moduleDataSetup]);
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $attributeId = (int)$this->eavSetup->getAttributeId(Product::ENTITY, self::ATTRIBUTE_CODE);
        if ($attributeId) {
            $labelToId = $this->ensureOptions($attributeId);
            $this->writeConfig($labelToId);
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * Ensures every required production-type option exists (case-insensitive
     * match against existing options, so "HANDKNOTTED" is reused as
     * "Handknotted" rather than duplicated) and returns a label => option_id map.
     *
     * @return array<string,int> label (as declared here) => option id
     */
    private function ensureOptions(int $attributeId): array
    {
        $existing = $this->loadOptions($attributeId); // ['normalizedLabel' => id]
        $result = [];

        foreach (self::PRODUCTION_TYPES as $label) {
            $key = $this->normalize($label);
            if (isset($existing[$key])) {
                $result[$label] = $existing[$key];
                continue;
            }

            $this->eavSetup->addAttributeOption([
                'attribute_id' => $attributeId,
                'values' => [0 => $label],
            ]);

            // Re-read to capture the freshly assigned option id.
            $existing = $this->loadOptions($attributeId);
            if (isset($existing[$key])) {
                $result[$label] = $existing[$key];
            }
        }

        return $result;
    }

    /**
     * @return array<string,int> normalized admin-store label => option id
     */
    private function loadOptions(int $attributeId): array
    {
        $connection = $this->moduleDataSetup->getConnection();
        $select = $connection->select()
            ->from(['o' => $this->moduleDataSetup->getTable('eav_attribute_option')], ['option_id'])
            ->join(
                ['v' => $this->moduleDataSetup->getTable('eav_attribute_option_value')],
                'v.option_id = o.option_id AND v.store_id = 0',
                ['value']
            )
            ->where('o.attribute_id = ?', $attributeId);

        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $label = trim((string)$row['value']);
            if ($label === '') {
                continue;
            }
            $map[$this->normalize($label)] = (int)$row['option_id'];
        }

        return $map;
    }

    private function normalize(string $label): string
    {
        return mb_strtolower(trim($label));
    }

    /**
     * @param array<string,int> $labelToId
     */
    private function writeConfig(array $labelToId): void
    {
        $selectedIds = [];
        foreach (self::COA_SELECTED_TYPES as $label) {
            if (isset($labelToId[$label])) {
                $selectedIds[] = $labelToId[$label];
            }
        }

        $values = [
            'sales_pdf/coa/production_type'    => implode(',', $selectedIds),
            'sales_pdf/coa/row_price_limit'    => '0',
            'sales_pdf/coa/include_signature'  => '0',
            'sales_pdf/coa/footer_text'        => self::FOOTER_TEXT,
            'sales_pdf/coa/attach_to_shipment' => '0',
        ];

        foreach ($values as $path => $value) {
            $this->configWriter->save($path, $value);
        }
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
