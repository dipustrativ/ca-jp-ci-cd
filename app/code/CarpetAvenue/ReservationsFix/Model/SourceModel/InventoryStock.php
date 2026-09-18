<?php

declare(strict_types=1);

namespace CarpetAvenue\ReservationsFix\Model\SourceModel;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\InventoryApi\Api\Data\SourceInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\InventoryApi\Api\StockRepositoryInterface;

class InventoryStock implements OptionSourceInterface
{
    protected SearchCriteriaBuilder $searchCriteriaBuilder;
    protected StockRepositoryInterface $stockRepository;

    public function __construct(
        StockRepositoryInterface $stockRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->stockRepository = $stockRepository;
    }

    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->create();
        $sourcesSearchResult = $this->stockRepository->getList($searchCriteria);
        $sourcesList = $sourcesSearchResult->getItems();

        $optionArray = [];
        foreach ($sourcesList as $source) {
            $optionArray[] = ['value' => $source->getStockId(), 'label' => $source->getName()];
        }
        return $optionArray;
    }
}
