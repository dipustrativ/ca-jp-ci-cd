<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Ensures a VAT-exempt customer tax class exists and assigns it to the Tax-Free
 * group (id 3). Consumer/Business keep the default "Retail Customer" class.
 * Idempotent: reuses the tax class if it already exists.
 *
 * @package CarpetAvenue_VAT
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\VAT\Setup\Patch\Data;

use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Tax\Api\Data\TaxClassInterface;
use Magento\Tax\Api\Data\TaxClassInterfaceFactory;
use Magento\Tax\Api\TaxClassRepositoryInterface;
use Magento\Tax\Model\ClassModel;
use Magento\Tax\Model\ResourceModel\TaxClass\CollectionFactory;

class SetupTaxClasses implements DataPatchInterface
{
    private const TAX_FREE_CLASS_NAME = 'Tax-Free Customer';

    public function __construct(
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly TaxClassRepositoryInterface $taxClassRepository,
        private readonly TaxClassInterfaceFactory $taxClassFactory,
        private readonly CollectionFactory $taxClassCollectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        $classId = $this->ensureTaxFreeCustomerClass();

        try {
            $group = $this->groupRepository->getById(SetupCustomerGroups::GROUP_TAX_FREE);
        } catch (NoSuchEntityException $e) {
            return $this;
        }

        if ((int)$group->getTaxClassId() !== $classId) {
            $group->setTaxClassId($classId);
            $this->groupRepository->save($group);
        }

        return $this;
    }

    private function ensureTaxFreeCustomerClass(): int
    {
        $collection = $this->taxClassCollectionFactory->create()
            ->addFieldToFilter('class_name', self::TAX_FREE_CLASS_NAME)
            ->addFieldToFilter('class_type', ClassModel::TAX_CLASS_TYPE_CUSTOMER)
            ->setPageSize(1);

        $existing = $collection->getFirstItem();
        if ($existing->getData('class_id')) {
            return (int)$existing->getData('class_id');
        }

        /** @var TaxClassInterface $taxClass */
        $taxClass = $this->taxClassFactory->create();
        $taxClass->setClassName(self::TAX_FREE_CLASS_NAME);
        $taxClass->setClassType(ClassModel::TAX_CLASS_TYPE_CUSTOMER);

        return (int)$this->taxClassRepository->save($taxClass);
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [SetupCustomerGroups::class];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
