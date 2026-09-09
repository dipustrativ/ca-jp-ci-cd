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
 * Creates the "Forgot Password JP" transactional email template and selects it
 * for the forgot/remind password emails plus the sender identity
 * (default scope).
 *
 *   customer/password/forgot_email_template = <new template id>
 *   customer/password/remind_email_template = <new template id>
 *   customer/password/forgot_email_identity = general
 */
class CreateForgotPasswordEmailTemplate implements DataPatchInterface
{
    private const TEMPLATE_CODE = 'Forgot Password JP';
    private const ORIG_TEMPLATE = 'customer_password_forgot_email_template';

    /** @var string[] config paths that receive the new template id */
    private const TEMPLATE_CONFIG_PATHS = [
        'customer/password/forgot_email_template',
        'customer/password/remind_email_template',
    ];

    private const SUBJECT =
        '{{trans " %store_name のご登録パスワードをリセットする" store_name=$store.frontend_name}}';

    private const BODY = <<<'HTML'
{{template config_path="design/email/header_template"}}

<p class="greeting">{{trans "  %name 様" name=$customer.name}}</p>
<p>{{trans "アカウントのパスワードの変更申請がありました。"}}</p>
<p>{{trans "パスワードを変更をするには、こちらに新しいパスワードをご入力ください。"}}</p>

<table class="button" width="100%" border="0" cellspacing="0" cellpadding="0">
    <tr>
        <td>
            <table class="inner-wrapper" border="0" cellspacing="0" cellpadding="0" align="center">
                <tr>
                    <td align="center">
<a href="{{var this.getUrl($store,'customer/account/createPassword/',[_query:[id:$customer.id,token:$customer.rp_token],_nosid:1])}}" target="_blank">{{trans "新しいパスワードを設定する"}}</a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<p>{{trans " もしお心当たりがない場合は本メールを破棄してください。ご使用中のパスワードを継続してお使いいただけます。"}}</p>

<p>{{trans "<br>%store_name" store_name=$store.frontend_name |raw}}</p>

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
            foreach (self::TEMPLATE_CONFIG_PATHS as $path) {
                $this->configWriter->save($path, (string)$template->getId());
            }
        }

        $this->configWriter->save('customer/password/forgot_email_identity', 'general');

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
