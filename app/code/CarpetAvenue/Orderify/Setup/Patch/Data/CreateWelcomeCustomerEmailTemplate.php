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
 * Creates the "Welcome as a customer JP" transactional email template and
 * selects it for every new-account email variant plus the sender identity
 * (default scope).
 *
 *   customer/create_account/email_template              = <new template id>
 *   customer/create_account/email_no_password_template  = <new template id>
 *   customer/create_account/email_confirmed_template    = <new template id>
 *   customer/create_account/email_confirmation_template = <new template id>
 *   customer/create_account/email_identity              = general
 */
class CreateWelcomeCustomerEmailTemplate implements DataPatchInterface
{
    private const TEMPLATE_CODE = 'Welcome as a customer JP';
    private const ORIG_TEMPLATE = 'customer_create_account_email_template';

    /** @var string[] config paths that receive the new template id */
    private const TEMPLATE_CONFIG_PATHS = [
        'customer/create_account/email_template',
        'customer/create_account/email_no_password_template',
        'customer/create_account/email_confirmed_template',
        'customer/create_account/email_confirmation_template',
    ];

    private const SUBJECT = '{{trans "%store_name へようこそ" store_name=$store.frontend_name}}';

    private const BODY = <<<'HTML'
{{template config_path="design/email/header_template"}}

<p class="greeting">{{trans "%name 様 " name=$customer.name}}</p>

<p>
    {{trans
        '弊社のアカウントにご登録いただきまして誠にありがとうございます。ご登録いただいたお客様に特別な割引特典や新着情報をお届けいたします。'}}</p>
<p>
    {{trans
        'ログインの際には、下記の認証情報をご利用ください。
 <a href="%customer_url">アカウントページ：</a>:'

        customer_url=$this.getUrl($store,'customer/account/',[_nosid:1])
    |raw}}
</p>
<ul>
    <li><strong>{{trans "メールアドレス："}}</strong> {{var customer.email}}</li>
    <li><strong>{{trans "パスワード："}}</strong> <em>{{trans "アカウント作成の際に設定されたもの"}}</em></li>
</ul>
<p>
    {{trans
        '<a href="%reset_url">パスワードをお忘れの場合、こちらをクリックして再設定をお願いします。
</a> '

        reset_url="$this.getUrl($store,'customer/account/createPassword/',[_query:[id:$customer.id,token:$customer.rp_token],_nosid:1])"
    |raw}}
</p>

<p>{{trans "アカウントにログインいただくと、以下のサービスをご利用いただけます。"}}</p>
<table class="email-features">
    <tr>
        <td>
            <table>
                <tr>
                    <td>
                        <img src="{{view url='Magento_Customer/images/icn_checkout.png'}}" height="30" width="30" alt="{{trans 'Quick Checkout'}}" />
                    </td>
                    <td>
                        <h3>{{trans "迅速な決済"}}</h3>
                    </td>
                </tr>
            </table>
        </td>
        <td>
            <table>
                <tr>
                    <td>
                        <img src="{{view url='Magento_Customer/images/icn_status.png'}}" height="30" width="30" alt="{{trans 'Order Status'}}" />
                    </td>
                    <td>
                        <h3>{{trans "配送状況の追跡"}}</h3>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td>
            <table>
                <tr>
                    <td>
                        <img src="{{view url='Magento_Customer/images/icn_address.png'}}" height="30" width="30" alt="{{trans 'Manage Addresses'}}" />
                    </td>
                    <td>
                        <h3>{{trans "複数のお届け先住所の登録"}}</h3>
                    </td>
                </tr>
            </table>
        </td>
        <td>
            <table>
                <tr>
                    <td>
                        <img src="{{view url='Magento_Customer/images/icn_history.png'}}" height="30" width="30" alt="{{trans 'Order History'}}" />
                    </td>
                    <td>
                        <h3>{{trans "ご注文内容の確認"}}</h3>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</p>

<p>

<p>{{trans "弊社ウェブサイトに掲載されている絨毯についてのご質問、最近お買い求め頂いた商品、また絨毯全般に関するご質問やご不明な点がございましたらお気軽にお問い合わせください。"}}</p>

<p>お客様からのお問い合わせをお待ちしております。</p>
<p>敬具,</p>
{{config path="trans_email/ident_general/name"}}
{{template config_path="design/email/footer_template" is_newsletter="true"}}
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
            foreach (self::TEMPLATE_CONFIG_PATHS as $path) {
                $this->configWriter->save($path, (string)$template->getId());
            }
        }

        $this->configWriter->save('customer/create_account/email_identity', 'general');

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
