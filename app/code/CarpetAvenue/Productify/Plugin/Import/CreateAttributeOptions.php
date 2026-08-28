<?php

namespace CarpetAvenue\Productify\Plugin\Import;

use Magento\Catalog\Api\ProductAttributeOptionManagementInterface;
use Magento\CatalogImportExport\Model\Import\Product as ImportProduct;
use Magento\CatalogImportExport\Model\Import\Product\Validator;
use Magento\Eav\Api\Data\AttributeOptionInterfaceFactory;
use Magento\Eav\Api\Data\AttributeOptionLabelInterfaceFactory;
use Magento\Eav\Model\Config as EavConfig;
use Psr\Log\LoggerInterface;

/**
 * Auto-creates missing dropdown/multiselect option values during product import.
 *
 * The import validator rejects any select/multiselect value that is not already an
 * existing option with:
 *   "Value for '<attr>' attribute contains incorrect value, see acceptable values..."
 * This before-plugin detects those missing values, creates the options on the fly,
 * and injects them into $attrParams['options'] so validation passes.
 *
 * @see \Magento\CatalogImportExport\Model\Import\Product\Validator::isAttributeValid()
 *
 * UPGRADE RISK: isAttributeValid() is public but not @api. Re-verify its signature
 * after any Magento minor upgrade.
 *
 * SAVE-PHASE: validation reads $attrParams['options'] (patched below); the save phase
 * instead reads the import type-model cache (AbstractType::$_attributes[...]['options'],
 * built once in _initAttributes). Clearing the EAV cache does NOT refresh that static
 * import cache, so a created option would validate but persist as NULL on the same run.
 * We therefore also push the new option into the type-model via addAttributeOption() —
 * Magento's own hook for options created mid-import — making the import single-run.
 */
class CreateAttributeOptions
{
    private const TYPE_SELECT = 'select';
    private const TYPE_MULTISELECT = 'multiselect';

    /** Default separator for multiple values inside one multiselect column. */
    private const VALUE_SEPARATOR = '|';

    /**
     * In-run guard so the same value is not created twice across rows.
     * Key: "<attributeCode>::<lowercased label>" => option id.
     *
     * @var array<string, int>
     */
    private array $createdCache = [];

    /**
     * Import context captured from Validator::init(); gives access to the product
     * type model whose options cache the save phase reads.
     *
     * @var ImportProduct|null
     */
    private ?ImportProduct $context = null;

    public function __construct(
        private readonly ProductAttributeOptionManagementInterface $optionManagement,
        private readonly AttributeOptionInterfaceFactory           $optionFactory,
        private readonly AttributeOptionLabelInterfaceFactory      $optionLabelFactory,
        private readonly EavConfig                                 $eavConfig,
        private readonly LoggerInterface                           $logger,
    )
    {
    }

    /**
     * Capture the import context so the save-phase type-model cache can be updated.
     *
     * @param mixed $result
     * @param ImportProduct $context
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterInit(Validator $subject, $result, $context)
    {
        $this->context = $context;
        return $result;
    }

    /**
     * @param string $attrCode
     * @param array<string,mixed> $attrParams
     * @param array<string,mixed> $rowData
     * @return array{0:string,1:array<string,mixed>,2:array<string,mixed>}
     */
    public function beforeIsAttributeValid(
        Validator $subject,
                  $attrCode,
        array     $attrParams,
        array     $rowData
    ): array
    {
        try {
            $type = $attrParams['type'] ?? null;
            if (!in_array($type, [self::TYPE_SELECT, self::TYPE_MULTISELECT], true)) {
                return [$attrCode, $attrParams, $rowData];
            }

            $raw = $rowData[$attrCode] ?? '';

            // The import validator hands multiselect values either as a
            // "|"-separated string or as an already-split array. Normalise both
            // to a flat list of scalar labels (casting each element to string).
            if (is_array($raw)) {
                $values = $raw;
            } else {
                if ($raw === '' || $raw === null) {
                    return [$attrCode, $attrParams, $rowData];
                }
                $values = $type === self::TYPE_MULTISELECT
                    ? explode(self::VALUE_SEPARATOR, (string)$raw)
                    : [(string)$raw];
            }

            // Option keys in $attrParams['options'] are lowercased labels => option id.
            $options = $attrParams['options'] ?? [];
            $typeModel = $this->resolveTypeModel($rowData);

            foreach ($values as $value) {
                $value = trim((string)$value);
                if ($value === '') {
                    continue;
                }

                $key = mb_strtolower($value);
                if (isset($options[$key])) {
                    continue; // already a valid option
                }

                $optionId = $this->createIfMissing((string)$attrCode, $value);
                if ($optionId !== null) {
                    // Patch validator copy so validateOption() passes.
                    $options[$key] = $optionId;
                    // Patch save-phase type-model cache so the value maps to this id.
                    if ($typeModel !== null) {
                        $typeModel->addAttributeOption((string)$attrCode, $key, (string)$optionId);
                    }
                }
            }

            $attrParams['options'] = $options;
        } catch (\Throwable $e) {
            // Never let this break the import; degrade to native behaviour (row will
            // simply fail validation as before) and record why.
            $this->logger->error(
                sprintf(
                    'Strativ_AutoAttributeOption: could not create option(s) for "%s": %s',
                    (string)$attrCode,
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
        }

        return [$attrCode, $attrParams, $rowData];
    }

    /**
     * Return the option id for $label on $attributeCode, creating it if absent.
     * Duplicate-safe: checks existing options and an in-run cache first.
     */
    private function createIfMissing(string $attributeCode, string $label): ?int
    {
        $cacheKey = $attributeCode . '::' . mb_strtolower($label);
        if (isset($this->createdCache[$cacheKey])) {
            return $this->createdCache[$cacheKey];
        }

        $existingId = $this->findOptionId($attributeCode, $label);
        if ($existingId !== null) {
            return $this->createdCache[$cacheKey] = $existingId;
        }

        $optionLabel = $this->optionLabelFactory->create();
        $optionLabel->setStoreId(0);
        $optionLabel->setLabel($label);

        $option = $this->optionFactory->create();
        $option->setLabel($label);
        $option->setStoreLabels([$optionLabel]);
        $option->setSortOrder(0);
        $option->setIsDefault(false);

        // Catalog\ProductAttributeOptionManagementInterface::add() takes (attributeCode, option).
        // Do NOT prepend an entity-type arg (that is the Eav interface signature) — doing so
        // shifts the option into the attributeCode slot and triggers a Swatches TypeError.
        $this->optionManagement->add($attributeCode, $option);

        // Force fresh reads so both this validation pass and the save pass see it.
        $this->eavConfig->clear();

        $newId = $this->findOptionId($attributeCode, $label);
        if ($newId === null) {
            $this->logger->warning(
                sprintf(
                    'Strativ_AutoAttributeOption: created "%s" on "%s" but could not resolve its id.',
                    $label,
                    $attributeCode
                )
            );
            return null;
        }

        $this->logger->info(
            sprintf('Strativ_AutoAttributeOption: created option "%s" (id %d) on "%s".', $label, $newId, $attributeCode)
        );

        return $this->createdCache[$cacheKey] = $newId;
    }

    /**
     * Case-insensitive lookup of an option id by its admin label.
     */
    private function findOptionId(string $attributeCode, string $label): ?int
    {
        $needle = mb_strtolower($label);
        foreach ($this->optionManagement->getItems($attributeCode) as $option) {
            $optLabel = (string)$option->getLabel();
            if ($optLabel !== '' && mb_strtolower($optLabel) === $needle) {
                $value = $option->getValue();
                if ($value !== null && $value !== '') {
                    return (int)$value;
                }
            }
        }
        return null;
    }

    /**
     * Resolve the product type model for the current row from the import context.
     * Its options cache is what the save phase reads.
     *
     * @param array<string,mixed> $rowData
     * @return \Magento\CatalogImportExport\Model\Import\Product\Type\AbstractType|null
     */
    private function resolveTypeModel(array $rowData)
    {
        $productType = $rowData['product_type'] ?? null;
        if ($this->context === null || !$productType) {
            return null;
        }

        return $this->context->retrieveProductTypeByName($productType) ?: null;
    }
}
