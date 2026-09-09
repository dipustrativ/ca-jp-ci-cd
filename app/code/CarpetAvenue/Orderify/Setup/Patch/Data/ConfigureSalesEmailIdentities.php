<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Seeds the sales-email sender identities, the shipment Trustpilot BCC, and the
 * reminder/cancellation enabled payment methods (default scope).
 *
 *   sales_email/order/identity                                          = general
 *   sales_email/invoice/identity                                        = general
 *   sales_email/shipment/identity                                       = general
 *   sales_email/creditmemo/identity                                     = general
 *   sales_email/order_ready_for_pickup/identity                         = general
 *   sales_email/shipment/copy_to                                        = Trustpilot invite BCC
 *   sales_email/payment_method_for_reminder_and_cancellation/payment_method = banktransfer,evinvoicepayment
 */
class ConfigureSalesEmailIdentities implements DataPatchInterface
{
    /** @var array<string,string> config path => value */
    private const CONFIG = [
        'sales_email/order/identity'                  => 'general',
        'sales_email/invoice/identity'                => 'general',
        'sales_email/shipment/identity'               => 'general',
        'sales_email/creditmemo/identity'             => 'general',
        'sales_email/order_ready_for_pickup/identity' => 'general',
        'sales_email/shipment/copy_to'                => 'carpetavenue.jp+a33a186d37@invite.trustpilot.com',
        'sales_email/payment_method_for_reminder_and_cancellation/payment_method'
            => 'banktransfer,evinvoicepayment',
    ];

    public function __construct(
        private ModuleDataSetupInterface $moduleDataSetup,
        private WriterInterface $configWriter
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        foreach (self::CONFIG as $path => $value) {
            $this->configWriter->save($path, $value);
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
