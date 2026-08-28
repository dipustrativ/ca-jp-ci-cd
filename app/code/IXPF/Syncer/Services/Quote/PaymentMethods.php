<?php
declare(strict_types=1);

namespace IXPF\Syncer\Services\Quote;

use Exception;
use IXPF\Syncer\Helper\Config;
use Magento\Payment\Api\PaymentMethodListInterface;
use Magento\Quote\Api\CartRepositoryInterface;

/**
 * List active payment methods for the store the quote belongs to,
 * mirroring the admin order-create payment panel.
 * When "Allowed Payment Methods" is configured, the list is filtered to those
 * codes only; empty config = return all active methods.
 */
class PaymentMethods
{
    public function __construct(
        protected readonly CartRepositoryInterface   $cartRepository,
        protected readonly PaymentMethodListInterface $paymentMethodList,
        protected readonly Config                     $config
    ) {
    }

    /**
     * @throws Exception
     */
    public function process(int $quoteId): array
    {
        $quote   = $this->cartRepository->get($quoteId);
        $storeId = (int)$quote->getStoreId();

        $allowed = $this->config->getOrderCreatePaymentMethods($storeId);

        $methods = [];
        foreach ($this->paymentMethodList->getActiveList($storeId) as $method) {
            $code = $method->getCode();
            if ($allowed && !in_array($code, $allowed, true)) {
                continue;
            }
            $methods[] = [
                'code'  => $code,
                'title' => $method->getTitle(),
            ];
        }

        return ['quote_id' => $quoteId, 'methods' => $methods];
    }
}
