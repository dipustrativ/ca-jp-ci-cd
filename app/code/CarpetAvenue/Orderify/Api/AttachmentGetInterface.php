<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Api;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * GET /V1/orders/:orderId/attachment
 *
 * @api
 */
interface AttachmentGetInterface
{
    /**
     * Stream the order's attachment PDF as the raw response body.
     *
     * Only served when the order's "extra shipping instructions" flag is on.
     *
     * @param int $orderId Order entity ID
     * @return string
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    public function execute($orderId);
}
