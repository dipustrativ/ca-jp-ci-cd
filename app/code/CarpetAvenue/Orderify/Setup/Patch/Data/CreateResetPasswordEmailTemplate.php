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
 * Creates the "Reset Password JP" transactional email template and selects it
 * as the reset-password confirmation email (default scope).
 *
 *   customer/password/reset_password_template = <new template id>
 */
class CreateResetPasswordEmailTemplate implements DataPatchInterface
{
    private const TEMPLATE_CODE = 'Reset Password JP';
    private const ORIG_TEMPLATE = 'customer_password_reset_password_template';
    private const CONFIG_PATH = 'customer/password/reset_password_template';

    private const SUBJECT =
        '{{trans "%store_name のご登録パスワードが変更されました。" store_name=$store.frontend_name}}';

    private const BODY = <<<'HTML'
{{template config_path="design/email/header_template"}}

<p class="greeting">{{trans "  %name 様" name=$customer.name}}</p>
<br>

<p>
    {{trans "ご登録のパスワードを変更致しました。%store_name " store_name=$store.frontend_name}}
    {{trans '本メールにお心当たりのない場合はメール <a href="mailto:%store_email">%store_email</a>' store_email=$store_email |raw}}{{depend store_phone}} {{trans 'もしくはお電話 にてお問い合わせください。<a href="tel:%store_phone">%store_phone</a>' store_phone=$store_phone |raw}}{{/depend}}.
</p>
<br>

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
