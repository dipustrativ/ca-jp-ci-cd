<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\Reviews;

use IXPF\Syncer\Api\Data\ReviewSummaryInterface;

class ReviewSummary implements ReviewSummaryInterface
{
    /**
     * @param int[] $distribution five values, index 0 is 1-star
     */
    public function __construct(
        private readonly int $count = 0,
        private readonly int $withTextCount = 0,
        private readonly float $average = 0.0,
        private readonly array $distribution = [0, 0, 0, 0, 0],
        private readonly ?float $averageQuality = null,
        private readonly ?float $averageDelivery = null,
        private readonly ?float $averageCustomerService = null,
        private readonly ?string $label = null,
        private readonly int $reviewCount = 0,
        private readonly int $textCount = 0,
        private readonly int $photoCount = 0
    ) {
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function getWithTextCount(): int
    {
        return $this->withTextCount;
    }

    public function getAverage(): float
    {
        return $this->average;
    }

    public function getDistribution(): array
    {
        return $this->distribution;
    }

    public function getAverageQuality(): ?float
    {
        return $this->averageQuality;
    }

    public function getAverageDelivery(): ?float
    {
        return $this->averageDelivery;
    }

    public function getAverageCustomerService(): ?float
    {
        return $this->averageCustomerService;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getReviewCount(): int
    {
        return $this->reviewCount;
    }

    public function getTextCount(): int
    {
        return $this->textCount;
    }

    public function getPhotoCount(): int
    {
        return $this->photoCount;
    }
}
