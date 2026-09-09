<?php

declare(strict_types=1);

namespace Evalent\InvoicePaymentMods\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Seeds the invoice PDF footer columns for the Evalent Invoice Payment method
 * (default scope).
 *
 *   payment/evinvoicepayment/pdf_footer_type  = 2 (three-section footer)
 *   payment/evinvoicepayment/pdf_footer_left  = seller / company details
 *   payment/evinvoicepayment/pdf_footer_right = bank account details
 */
class ConfigureInvoicePdfFooter implements DataPatchInterface
{
    private const FOOTER_LEFT = <<<'TEXT'
販売業者: 株式会社 Carpet Avenue Japan

業務執行取締役: 中川シャーロム

所在地: 〒248-0022 神奈川県鎌倉市常盤362-4(店舗)

電話番号: 0467-33-5349
TEXT;

    private const FOOTER_RIGHT = <<<'TEXT'
銀行口座情報

銀行名:三菱UFJ銀行 鎌倉支店 支店番号255

口座名義人:(株) Carpet Avenue Japan

口座番号0243682   普通口座
TEXT;

    public function __construct(
        private ModuleDataSetupInterface $moduleDataSetup,
        private WriterInterface $configWriter
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        // 2 = three-section footer (PdfFooterTypes::INVOICE_PDF_STYLE_TRIPLE) — without this the
        // seeded left/right text stays hidden in admin and never renders on the PDF.
        $this->configWriter->save(
            'payment/evinvoicepayment/pdf_footer_type',
            (string) \Evalent\InvoicePaymentMods\Model\Config\Source\PdfFooterTypes::INVOICE_PDF_STYLE_TRIPLE
        );
        $this->configWriter->save('payment/evinvoicepayment/pdf_footer_left', self::FOOTER_LEFT);
        $this->configWriter->save('payment/evinvoicepayment/pdf_footer_right', self::FOOTER_RIGHT);

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
