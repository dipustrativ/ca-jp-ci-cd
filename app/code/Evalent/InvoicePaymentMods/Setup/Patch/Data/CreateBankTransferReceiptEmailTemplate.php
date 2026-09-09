<?php

declare(strict_types=1);

namespace Evalent\InvoicePaymentMods\Setup\Patch\Data;

use Magento\Email\Model\Template;
use Magento\Email\Model\TemplateFactory;
use Magento\Email\Model\ResourceModel\Template as TemplateResource;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates the "Bank transfer Receipt (customer) JP" transactional email
 * template and selects it as the receipt email for the Evalent Invoice Payment
 * method (default scope).
 *
 *   payment/evinvoicepayment/email_template_receipt = <new template id>
 */
class CreateBankTransferReceiptEmailTemplate implements DataPatchInterface
{
    private const TEMPLATE_CODE = 'Bank transfer Receipt (customer) JP';
    private const ORIG_TEMPLATE = 'payment_de_evinvoicepayment_email_template_receipt';
    private const CONFIG_PATH = 'payment/evinvoicepayment/email_template_receipt';

    private const SUBJECT =
        '{{trans " %store_name でご注文された商品の領収書" store_name=$store.frontend_name}}';

    private const BODY = <<<'HTML'
{{template config_path="design/email/header_template"}}

<table>
    <tr class="email-intro">
        <td>
            <p class="greeting">{{trans " %name 様" name=$order_data.customer_name}}</p>
            <p>
       {{trans "ご入金を確認致しました。弊社倉庫にてご注文のお品物を用意いたします。発送準備が整いましたら詳細情報を記載した確認メールがお手元に届きます。"}}<br>
 {{trans '<a href="%account_url">ご注文の配送状況は、お客様のアカウントにログインして頂くことでご確認いただけます。
</a>' account_url=$this.getUrl($store,'customer/account/',[_nosid:1]) |raw}}<br>
                {{trans 'ご注文に関してご不明な点がございましたらお気軽にお問い合わせください。'}}
            </p>
  <p>{{trans "敬具, "}}<br>

{{config path="trans_email/ident_general/name"}}</p>
        </td>
    </tr>
    <tr class="email-summary">
        <td>
            <h1>{{trans "領収書番号 # %invoice_id ご注文番号 # %order_id" invoice_id=$invoice.increment_id order_id=$order.increment_id}}</h1>
        </td>
    </tr>
    <tr class="email-information">
        <td>
            {{depend comment}}
            <table class="message-info">
                <tr>
                    <td>
                        {{var comment|escape|nl2br}}
                    </td>
                </tr>
            </table>
            {{/depend}}
            <table class="order-details">
                <tr>
                    <td class="address-details">
                        <h3>{{trans "Billing Info"}}</h3>
                        <p>{{var formattedBillingAddress|raw}}</p>
                    </td>
                    {{depend order_data.is_not_virtual}}
                    <td class="address-details">
                        <h3>{{trans "Shipping Info"}}</h3>
                        <p>{{var formattedShippingAddress|raw}}</p>
                    </td>
                    {{/depend}}
                </tr>
                <tr>
                    <td class="method-info">
                        <h3>{{trans "Payment Method"}}</h3>
                        {{var payment_html|raw}}
                    </td>
                    {{depend order_data.is_not_virtual}}
                    <td class="method-info">
                        <h3>{{trans "Shipping Method"}}</h3>
                        <p>{{var order.shipping_description}}</p>
                    </td>
                    {{/depend}}
                </tr>
            </table>
            {{layout handle="sales_email_order_invoice_items" invoice_id=$invoice_id order_id=$order_id}}
        </td>
    </tr>
</table>

{{template config_path="design/email/footer_template"}}
HTML;

    public function __construct(
        private ModuleDataSetupInterface $moduleDataSetup,
        private TemplateFactory $templateFactory,
        private TemplateResource $templateResource,
        private WriterInterface $configWriter
    ) {
    }

    public function apply(): self
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var Template $template */
        $template = $this->templateFactory->create();
        $this->templateResource->load($template, self::TEMPLATE_CODE, 'template_code');

        if (!$template->getId()) {
            $template->setTemplateCode(self::TEMPLATE_CODE)
                ->setTemplateText(self::BODY)
                ->setTemplateSubject(self::SUBJECT)
                ->setTemplateType(Template::TYPE_HTML)
                ->setOrigTemplateCode(self::ORIG_TEMPLATE);
            $this->templateResource->save($template);
        }

        if ($template->getId()) {
            $this->configWriter->save(self::CONFIG_PATH, (string)$template->getId());
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
