<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Customer
 * @package   CarpetAvenue_ExportImport
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\ExportImport\Model\CustomerAddress;

use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;

/**
 * Turns a CSV row's `country_id` + `region` into a local `region_id`.
 *
 * `customer_address_entity.region_id` points at `directory_country_region`,
 * whose ids come from Magento's own data patches — so for a country both
 * installs got from the same Magento version they DO match, and for a region an
 * admin or a module added locally they do not. Resolving by name (or code) first
 * and only then falling back to the CSV's id is the difference between an
 * address landing in the right prefecture and landing in a random one.
 *
 * The region table is small (a few thousand rows across every country, ~50 per
 * country), so one query per country is loaded and cached for the run.
 */
class RegionResolver
{
    /** @var array<string, array<string, int>> country id => lowercased name|code => region id */
    private array $cache = [];

    public function __construct(
        private readonly RegionCollectionFactory $regionCollectionFactory,
    ) {
    }

    /**
     * The local region id for one address row, or null when there is none.
     *
     * Order of preference:
     *  1. `region` matched against the country's region names and codes — the
     *     value that means the same thing on both installs;
     *  2. the CSV's `region_id` verbatim, when the name matched nothing (a
     *     free-text region for a country with no region list, or a locally added
     *     region);
     *  3. null.
     *
     * @param array<string, string> $data one CSV row
     */
    public function resolve(array $data): ?int
    {
        $countryId = strtoupper(trim((string)($data['country_id'] ?? '')));
        $region = trim((string)($data['region'] ?? ''));

        if ($countryId !== '' && $region !== '') {
            $byName = $this->regionsOf($countryId);
            $found = $byName[strtolower($region)] ?? null;
            if ($found !== null) {
                return $found;
            }
        }

        $csvId = (int)($data['region_id'] ?? 0);
        return $csvId > 0 ? $csvId : null;
    }

    /**
     * Whether the row's `region` matched the country's region list.
     *
     * The validator uses this to warn: a row that falls through to the CSV's
     * `region_id` is the one case where the import trusts a foreign id.
     *
     * @param array<string, string> $data
     */
    public function matchedByName(array $data): bool
    {
        $countryId = strtoupper(trim((string)($data['country_id'] ?? '')));
        $region = trim((string)($data['region'] ?? ''));
        if ($countryId === '' || $region === '') {
            return false;
        }
        return isset($this->regionsOf($countryId)[strtolower($region)]);
    }

    /** Whether the country has a region list at all. */
    public function hasRegions(string $countryId): bool
    {
        return $this->regionsOf(strtoupper(trim($countryId))) !== [];
    }

    /**
     * One country's regions, keyed by both their name and their code, lowercased.
     *
     * @return array<string, int>
     */
    private function regionsOf(string $countryId): array
    {
        if (isset($this->cache[$countryId])) {
            return $this->cache[$countryId];
        }

        $map = [];
        if ($countryId !== '') {
            $collection = $this->regionCollectionFactory->create();
            $collection->addCountryFilter($countryId);
            foreach ($collection as $region) {
                $id = (int)$region->getId();
                foreach ([$region->getDefaultName(), $region->getName(), $region->getCode()] as $value) {
                    $value = strtolower(trim((string)$value));
                    if ($value !== '') {
                        $map[$value] ??= $id;
                    }
                }
            }
        }

        return $this->cache[$countryId] = $map;
    }
}
