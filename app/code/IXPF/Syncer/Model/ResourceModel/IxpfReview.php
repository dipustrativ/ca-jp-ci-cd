<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class IxpfReview extends AbstractDb
{
    public const TABLE  = 'ixpf_review';
    public const ENTITY = 'entity_id';

    public function _construct()
    {
        $this->_init(self::TABLE, self::ENTITY);
    }
}
