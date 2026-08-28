<?php

declare(strict_types=1);

namespace Carpient\Filter\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for carpient_filter_option_image.
 */
class OptionImage extends AbstractDb
{
    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init('carpient_filter_option_image', 'entity_id');
    }

    /**
     * Load an option-image row by its option_id.
     *
     * @param int $optionId
     * @return array
     */
    public function loadByOptionId(int $optionId): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->where('option_id = ?', $optionId)
            ->limit(1);

        $row = $connection->fetchRow($select);

        return $row ?: [];
    }
}
