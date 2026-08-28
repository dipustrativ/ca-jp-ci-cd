<?php

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Ui\DataProvider;

use Magento\Framework\Api\Filter;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Ui\DataProvider\AbstractDataProvider;

/**
 * Provides data for the utilities_region_form UI component.
 * When region_id is present in the request it loads the existing row for editing;
 * otherwise it returns an empty array (new-region form).
 */
class RegionFormDataProvider extends AbstractDataProvider
{
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        private RequestInterface $request,
        private ResourceConnection $resourceConnection,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
    }

    /**
     * Returns form data keyed by region_id, or empty array for a new region.
     */
    public function getData(): array
    {
        $regionId = (int)$this->request->getParam('region_id');

        if (!$regionId) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $table      = $this->resourceConnection->getTableName('directory_country_region');

        $row = $connection->fetchRow(
            $connection->select()->from($table)->where('region_id = ?', $regionId)
        );

        if (!$row) {
            return [];
        }

        // Key by region_id – this is the convention Magento form data-providers use
        return [$regionId => $row];
    }

    /**
     * No-op overrides – collection is not used for a single-record form provider.
     * Without these, AbstractDataProvider calls addFieldToFilter() on a null collection.
     */
    public function addFilter(Filter $filter): void {}

    public function addOrder($field, $direction): void {}

    public function setLimit($offset, $size): void {}

    public function getSearchResult(): null
    {
        return null;
    }
}

