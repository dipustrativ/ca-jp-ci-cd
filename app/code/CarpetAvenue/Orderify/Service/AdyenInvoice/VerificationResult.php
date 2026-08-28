<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Order
 * @package   CarpetAvenue_Orderify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Service\AdyenInvoice;

/**
 * Outcome of local Adyen payment verification for one order.
 */
class VerificationResult
{
    /** Payment confirmed and already captured — invoice offline. */
    public const SUCCESS_CAPTURED = 'success_captured';

    /** Payment confirmed authorised but not captured — invoice with online capture. */
    public const SUCCESS_AUTHORISED = 'success_authorised';

    /** No successful payment found — flag order, comment once, leave untouched. */
    public const FAIL = 'fail';

    /** Successful webhook exists but Adyen's own cron has not processed it yet — retry next run. */
    public const DEFER = 'defer';

    public function __construct(
        public readonly string $status,
        public readonly string $reason
    ) {
    }

    public function isSuccess(): bool
    {
        return in_array($this->status, [self::SUCCESS_CAPTURED, self::SUCCESS_AUTHORISED], true);
    }

    public function isDefer(): bool
    {
        return $this->status === self::DEFER;
    }
}
