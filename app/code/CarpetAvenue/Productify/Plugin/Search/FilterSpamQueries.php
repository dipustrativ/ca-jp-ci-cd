<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\Plugin\Search;

use Magento\Search\Model\ResourceModel\Query\Collection;

/**
 * Keeps bot-generated terms out of the storefront's popular-search-terms list
 * (/search/term/popular/): only terms that actually matched something and were
 * searched more than a handful of times.
 *
 * Inherited from Evalent_SearchSuiteAutocomplete, which was removed along with
 * the MageWorx module it extended.
 */
class FilterSpamQueries
{
    /**
     * Drop no-hit and barely-searched terms before the collection loads
     *
     * @param Collection $subject
     * @param bool $printQuery
     * @param bool $logQuery
     * @return array
     */
    public function beforeLoad(Collection $subject, $printQuery = false, $logQuery = false): array
    {
        $subject->addFieldToFilter('num_results', ['gt' => 0]);
        $subject->addFieldToFilter('popularity', ['gt' => 5]);

        return [$printQuery, $logQuery];
    }
}
