<?php

declare(strict_types=1);

namespace IXPF\Syncer\Api;

use IXPF\Syncer\Api\Data\ReviewFilterInterface;
use IXPF\Syncer\Api\Data\ReviewListInterface;
use IXPF\Syncer\Api\Data\ReviewSummaryInterface;

/**
 * Read side of the review store. Built as a service so a future AJAX controller is a thin
 * wrapper over it.
 *
 * A caller starts from a product id and gets the pool key from getGroupKeyForProduct(). An
 * HTTP wrapper must never accept a review_group from the client — that would turn the endpoint
 * into a way to enumerate every pool on the platform.
 *
 * @api
 */
interface ReviewProviderInterface
{
    /**
     * @param int $productId
     * @return string|null null when the product has no review_group set
     */
    public function getGroupKeyForProduct(int $productId): ?string;

    /**
     * @param string $reviewGroup
     * @param string $locale
     * @return ReviewSummaryInterface zeroed summary for an unknown group, never an exception
     */
    public function getSummary(string $reviewGroup, string $locale): ReviewSummaryInterface;

    /**
     * @param string $reviewGroup
     * @param string $locale
     * @param ReviewFilterInterface $filter
     * @return ReviewListInterface empty list for an unknown group, never an exception
     */
    public function getReviews(
        string $reviewGroup,
        string $locale,
        ReviewFilterInterface $filter
    ): ReviewListInterface;
}
