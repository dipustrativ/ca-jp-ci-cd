<?php
declare(strict_types=1);

namespace IXPF\Syncer\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class IxpfPhotoStudioQueue extends AbstractDb
{

    const TABLE = 'ixpf_photo_studio_queue';
    const ENTITY = 'entity_id';

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_FAILED = 'failed';
    const STATUS_MAGENTO_FAILED = 'failed_by_magento';
    const STATUS_COMPLETED = 'completed';
    const STATUS_READY_FOR_WEBHOOK = 'ready_for_webhook';

    const STATUS_WEBHOOK_FAILED = 'webhook_failed';

    const PRODUCT_GALLERY_TYPE = 'product_gallery';

    protected function _construct(): void
    {
        $this->_init(self::TABLE, self::ENTITY);
    }
}