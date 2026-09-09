<?php

declare(strict_types=1);

namespace Evalent\InvoicePaymentMods\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Seeds the Evalent Invoice Payment (bank transfer) title and the payment
 * instructions block shown to the customer (default scope).
 *
 *   payment/evinvoicepayment/title                            = 銀行振込
 *   payment/evinvoicepayment/instructions_payment_information  = bank account details
 */
class ConfigureBankTransferInstructions implements DataPatchInterface
{
    private const TITLE = '銀行振込';

    private const INSTRUCTIONS = <<<'TEXT'
銀行口座情報

三菱UFJ銀行

鎌倉支店

支店番号255

(株) Carpet Avenue Japan

口座番号0243682

普通口座

 お振込の際、お客様のお名前をご入力ください。

お振込が５日以内に確認できない場合は、ご注文をキャンセルさせて頂きます。ご注文の配送状況は、お客様のアカウントからご確認いただけます。
TEXT;

    public function __construct(
        private ModuleDataSetupInterface $moduleDataSetup,
        private WriterInterface $configWriter
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $this->configWriter->save('payment/evinvoicepayment/title', self::TITLE);
        $this->configWriter->save(
            'payment/evinvoicepayment/instructions_payment_information',
            self::INSTRUCTIONS
        );

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
