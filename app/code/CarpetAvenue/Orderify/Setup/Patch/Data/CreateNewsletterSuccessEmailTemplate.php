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
 * Creates the "Successfully subscribed JP" transactional email template and
 * selects it as the newsletter subscription success email (default scope).
 *
 *   newsletter/subscription/success_email_template = <new template id>
 */
class CreateNewsletterSuccessEmailTemplate implements DataPatchInterface
{
    private const TEMPLATE_CODE = 'Successfully subscribed JP';
    private const ORIG_TEMPLATE = 'newsletter_subscription_success_email_template';
    private const CONFIG_PATH = 'newsletter/subscription/success_email_template';

    private const SUBJECT = '{{trans "ニュースレターの購読登録が完了しました。"}}';

    private const BODY = <<<'HTML'
{{template config_path="design/email/header_template"}}

<p class="greeting">{{trans "ご登録ありがとうございます。"}}

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
