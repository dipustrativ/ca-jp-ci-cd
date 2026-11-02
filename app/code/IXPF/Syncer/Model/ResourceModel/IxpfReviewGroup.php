<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class IxpfReviewGroup extends AbstractDb
{
    public const TABLE  = 'ixpf_review_group';
    public const ENTITY = 'review_group';

    /**
     * The id is a varchar the caller supplies, not an auto-increment, so Magento must not treat
     * a populated id as proof the row already exists.
     *
     * @var bool
     */
    protected $_isPkAutoIncrement = false;

    public function _construct()
    {
        $this->_init(self::TABLE, self::ENTITY);
    }
}
