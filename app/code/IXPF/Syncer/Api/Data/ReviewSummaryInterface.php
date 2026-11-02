<?php

declare(strict_types=1);

namespace IXPF\Syncer\Api\Data;

/**
 * Pool aggregates, read from the single ixpf_review_group row, plus three counts Magento takes
 * from the stored reviews (review, text, photo - cached per pool). The distribution never reflects
 * an applied filter — the bars must not move when the list is filtered.
 *
 * @api
 */
interface ReviewSummaryInterface
{
    /**
     * @return int
     */
    public function getCount(): int;

    /**
     * Reviews with a written body. getCount() is every rating including star-only ones, so this
     * is always the smaller of the two. Zero for a pool IXPF pushed before the field existed.
     *
     * @return int
     */
    public function getWithTextCount(): int;

    /**
     * @return float
     */
    public function getAverage(): float;

    /**
     * Five values, index 0 is 1-star.
     *
     * @return int[]
     */
    public function getDistribution(): array;

    /**
     * @return float|null
     */
    public function getAverageQuality(): ?float;

    /**
     * @return float|null
     */
    public function getAverageDelivery(): ?float;

    /**
     * @return float|null
     */
    public function getAverageCustomerService(): ?float;

    /**
     * Group label resolved for the requested locale, e.g. for "79 customers have reviewed
     * Hamadan". Null when the pool has no label in any usable locale.
     *
     * @return string|null
     */
    public function getLabel(): ?string;

    /**
     * Entries that count as a review under ixpf_reviews/general/review_criteria (text, a photo or
     * sub-ratings by default). The rest of getCount() are ratings: stars only.
     *
     * @return int
     */
    public function getReviewCount(): int;

    /**
     * Entries with written text. Above zero is what lets the drawer offer "Text only".
     *
     * @return int
     */
    public function getTextCount(): int;

    /**
     * Entries with at least one downloaded photo. Above zero is what lets the drawer offer
     * "Images only" - the filter needs a file on disk, so a photo still in the queue does not count.
     *
     * @return int
     */
    public function getPhotoCount(): int;
}
