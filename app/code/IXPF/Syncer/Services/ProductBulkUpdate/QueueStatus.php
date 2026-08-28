<?php

namespace IXPF\Syncer\Services\ProductBulkUpdate;

interface QueueStatus
{
    const PENDING = 'pending';
    const PROCESSING = 'processing';
    const COMPLETED = 'completed';
    const FAILED = 'failed';
    const READY_FOR_TRIGGER = 'ready_for_webhook_trigger';
    const MAGENTO_FAILED = 'magento_failed';

    const WEBHOOK_FAILED = 'webhook_failed';

}