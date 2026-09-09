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
 * Creates the "Order Confirmation (customer) JP" transactional email template
 * and selects it as the New Order confirmation email (default scope).
 *
 *   sales_email/order/template = <new template id>
 */
class CreateOrderConfirmationEmailTemplate implements DataPatchInterface
{
    private const TEMPLATE_CODE = 'Order Confirmation (customer) JP';
    private const ORIG_TEMPLATE = 'sales_email_order_template';
    private const CONFIG_PATH = 'sales_email/order/template';

    private const SUBJECT =
        '{{trans "%store_name におけるご注文内容の確認 " store_name=$store.frontend_name}}';

    private const BODY = <<<'HTML'
{{template config_path="design/email/header_template"}}

<table>
    <tr class="email-intro">
        <td>
            <p class="greeting">
               {{trans " %customer_name 様" customer_name=$order_data.customer_name}}
            </p>
            <p>
                {{trans "ご注文頂きありがとうございます。"}}
            </p>
            <p>
                <strong>{{trans "ご注文後の流れ"}}</strong>
            </p>
              <p>
                <strong>{{trans "-  クレジットカードでのお支払い :"}}</strong>
              </p>
  <p>

            <p>
                {{trans "弊社倉庫でお客様のお品物をご用意いたします。発送の準備が整いましたら、詳細と共に発送確認メールを送付致します。"}}
            </p>
            <p>
                {{trans '<a href="%account_url">ご注文の配送状況は、お客様のアカウントからご確認いただけます。</a>' account_url=$this.getUrl($store,'customer/account/',[_nosid:1]) |raw}}
            </p>
             <p>
               <strong>{{trans "- 銀行振込でのお支払い : "}}</strong>
            </p>
            <p>
                {{trans " 銀行振込をお選び頂いたお客様は、本メールに記載されております口座にお振込をお願い致します。振込手数料はお客様のご負担となります。ご入金が確認でき次第、お品物を発送いたします。"}}
            </p>
<p>
                {{trans " お振込の際、お客様のお名前をご入力ください。お振込が５日以内に確認できない場合は、ご注文をキャンセルさせて頂きます。"}}
            </p>
            <p>
                {{trans '<a href="%account_url">ご注文の配送状況は、お客様のアカウントからご確認いただけます。</a>' account_url=$this.getUrl($store,'customer/account/',[_nosid:1]) |raw}}
            </p>

            <p>
                {{trans 'ご注文に関してご不明な点がございましたらお気軽にお問い合わせください。'}}
            </p>
  <p>{{trans "敬具,"}}</p>
{{config path="trans_email/ident_general/name"}}
            <p>

            </p>
        </td>
    </tr>
    <tr class="email-summary">
        <td>
            <h1>{{trans 'Your Order <span class="no-link">#%increment_id</span>' increment_id=$order.increment_id |raw}}</h1>
            <p>{{trans 'Placed on <span class="no-link">%created_at</span>' created_at=$created_at_formatted |raw}}</p>
        </td>
    </tr>
    <tr class="email-information">
        <td>
            {{depend order_data.email_customer_note}}
            <table class="message-info">
                <tr>
                    <td>
                        {{var order_data.email_customer_note|escape|nl2br}}
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
                        {{if shipping_msg}}
                        <p>{{var shipping_msg}}</p>
                        {{/if}}
                    </td>
                    {{/depend}}
                </tr>
            </table>
            {{layout handle="sales_email_order_items" order_id=$order_id area="frontend"}}
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
