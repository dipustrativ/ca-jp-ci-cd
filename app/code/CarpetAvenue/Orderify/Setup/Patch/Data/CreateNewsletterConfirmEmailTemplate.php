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
 * Creates the "Newsletter Subscription Email Confirmation Template  JP"
 * transactional email template, selects it as the subscription confirmation
 * email and seeds the related newsletter subscription options (default scope).
 *
 *   newsletter/subscription/confirm_email_template   = <new template id>
 *   newsletter/subscription/confirm_email_identity   = general
 *   newsletter/subscription/success_email_identity   = general
 *   newsletter/subscription/un_email_identity        = general
 *   newsletter/subscription/disable_success_email    = 1
 *   newsletter/subscription/footer_standard_subscribe = 1
 *   newsletter/subscription/confirm                  = 0
 *   newsletter/subscription/allow_guest_subscribe    = 1
 */
class CreateNewsletterConfirmEmailTemplate implements DataPatchInterface
{
    private const TEMPLATE_CODE = 'Newsletter Subscription Email Confirmation Template  JP';
    private const ORIG_TEMPLATE = 'newsletter_subscription_confirm_email_template';
    private const CONFIG_TEMPLATE_PATH = 'newsletter/subscription/confirm_email_template';

    /** @var array<string,string> extra config path => value */
    private const CONFIG = [
        'newsletter/subscription/confirm_email_identity'    => 'general',
        'newsletter/subscription/success_email_identity'    => 'general',
        'newsletter/subscription/un_email_identity'         => 'general',
        'newsletter/subscription/disable_success_email'     => '1',
        'newsletter/subscription/footer_standard_subscribe' => '1',
        'newsletter/subscription/confirm'                   => '0',
        'newsletter/subscription/allow_guest_subscribe'     => '1',
    ];

    private const SUBJECT = '{{trans "ニュースレターご購読の確認をお願い致します。"}}';

    private const BODY = <<<'HTML'
{{template config_path="design/email/header_template"}}
{{var customer.name}}
<p class="greeting">{{trans " 弊社のニュースレターをご購読頂きありがとうございます。"}}</p>
<p>{{trans "配信を開始するため、次のリンクをクリックしてご購読を確定してください。"}}</p>
<p><a href="{{var subscriber_data.confirmation_link}}">{{var subscriber_data.confirmation_link}}</a></p>
<p>{{trans "お心当たりがない場合や、ニュースレターの購読をご希望されない場合は、本メールを破棄して頂けるようお願いします。"}}</p>
<p>{{trans "上記のリンクを開かない限り、ご購読は確定いたしません。"}}</p>
<p>ご不明な点がございましたら、メール info@carpetavenue.com にてお気軽にお問い合わせください。</p>

<p>ご購読は、弊社のウェブサイト、もしくはニュースレターに記載されているリンクから、いつでも解除できます。
</p>

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
            $this->configWriter->save(self::CONFIG_TEMPLATE_PATH, (string)$template->getId());
        }

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
