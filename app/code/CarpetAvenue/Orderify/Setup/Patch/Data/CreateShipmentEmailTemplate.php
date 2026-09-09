<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Setup\Patch\Data;

use Magento\Email\Model\Template;
use Magento\Email\Model\TemplateFactory;
use Magento\Email\Model\ResourceModel\Template as TemplateResource;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates the "Your order is ready to be shipped JP" transactional email
 * template and selects it as both the customer and guest New Shipment email
 * (default scope).
 *
 *   sales_email/shipment/template       = <new template id>
 *   sales_email/shipment/guest_template = <new template id>
 */
class CreateShipmentEmailTemplate implements DataPatchInterface
{
    private const TEMPLATE_CODE = 'Your order is ready to be shipped JP';
    private const ORIG_TEMPLATE = 'sales_email_shipment_template';
    private const CONFIG_PATHS = [
        'sales_email/shipment/template',
        'sales_email/shipment/guest_template',
    ];

    private const SUBJECT =
        '{{trans "出荷準備が完了しました" store_name=$store.frontend_name}}';

    private const BODY = <<<'HTML'
{{template config_path="design/email/header_template"}}

<table>
    <tr class="email-intro">
        <td>
            <p class="greeting">{{trans "  %name 様" name=$order_data.customer_name}}</p>
            <p>

            <p>
                {{trans " ご注文頂いた商品を本日発送いたします。追跡番号と詳細は以下をご覧ください。"}}
            </p>
            <p>
                {{trans ' 配送に関してご不明な点やご質問がございましたらお気軽にお問い合わせください。'}}
            </p>
            <p>
                {{trans " ご注文が無事到着しましたら、ご連絡を頂けると幸いです。"}}
    <p>{{trans "敬具,"}}<br>
{{config path="trans_email/ident_general/name"}}</p>
        </td>
    </tr>
    <tr class="email-summary">
        <td>
            <h1>{{trans "出荷番号 #%shipment_id ご注文番号 #%order_id" shipment_id=$shipment.increment_id order_id=$order.increment_id}}</h1>
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
            {{layout handle="sales_email_order_shipment_track" shipment_id=$shipment_id order_id=$order_id}}
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
            {{layout handle="sales_email_order_shipment_items" shipment_id=$shipment_id order_id=$order_id}}
        </td>
    </tr>
</table>

{{template config_path="design/email/footer_template"}}
<script type="application/json+trustpilot">
{
"recipientName": "{{var order_data.customer_name}}",
"recipientEmail": "{{var order.customer_email}}",
"referenceId": "{{var order.increment_id}}"
}
</script>
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
            foreach (self::CONFIG_PATHS as $path) {
                $this->configWriter->save($path, (string)$template->getId());
            }
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
