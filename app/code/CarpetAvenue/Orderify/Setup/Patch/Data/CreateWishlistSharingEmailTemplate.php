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
 * Creates the "Wishlist sharing JP" transactional email template and selects it
 * as the wishlist sharing email plus its sender identity (default scope).
 *
 *   wishlist/email/email_template = <new template id>
 *   wishlist/email/email_identity = general
 */
class CreateWishlistSharingEmailTemplate implements DataPatchInterface
{
    private const TEMPLATE_CODE = 'Wishlist sharing JP';
    private const ORIG_TEMPLATE = 'wishlist_email_email_template';
    private const CONFIG_TEMPLATE_PATH = 'wishlist/email/email_template';
    private const CONFIG_IDENTITY_PATH = 'wishlist/email/email_identity';

    private const SUBJECT =
        '{{trans "%customer_names 様のほしい物リストをご覧ください。" customer_name=$customerName}}';

    private const BODY = <<<'HTML'
{{template config_path="design/email/header_template"}}

<p>{{trans "%customer_name 様のほしい物リストをご覧ください。
 %store_name 様がカーペットアベニューからほしい物リストの共有をご希望されています。" customer_name=$customerName store_name=$store.frontend_name}}</p>

{{depend message}}
<table class="message-info">
    <tr>
        <td>
            <h3>{{trans "Message from %customer_name:" customer_name=$customerName}}</h3>
            {{var message|raw}}
        </td>
    </tr>
</table>
<br />
{{/depend}}

{{var items|raw}}
<br/>

<p><a href="{{var viewOnSiteLink}}">{{trans "ウィッシュリスト内のすべての絨毯を見る"}}</a></p>

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
            $this->configWriter->save(self::CONFIG_TEMPLATE_PATH, (string)$template->getId());
        }

        $this->configWriter->save(self::CONFIG_IDENTITY_PATH, 'general');

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
