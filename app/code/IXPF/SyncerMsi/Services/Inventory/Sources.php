<?php

declare(strict_types=1);

namespace IXPF\SyncerMsi\Services\Inventory;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\InventoryApi\Api\SourceRepositoryInterface;

class Sources
{
    public function __construct(
        protected SourceRepositoryInterface $sourceRepository,
        protected SearchCriteriaBuilder     $searchCriteriaBuilder
    ) {
    }

    /**
     * Return all inventory sources: source_code, name, is_enabled.
     *
     * @return array
     */
    public function getList(): array
    {
        $searchCriteria = $this->searchCriteriaBuilder->create();
        $sources = $this->sourceRepository->getList($searchCriteria)->getItems();

        $data = [];
        foreach ($sources as $source) {
            $data[] = [
                'source_code' => $source->getSourceCode(),
                'name'        => $source->getName(),
                'is_enabled'  => (bool)$source->isEnabled(),
            ];
        }

        return $data;
    }
}
