<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Setup\Patch\Data;

use Magento\Email\Model\Template;
use Magento\Email\Model\TemplateFactory;
use Magento\Email\Model\ResourceModel\Template as TemplateResource;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates the "FOOTER JP" transactional email footer template (localized links
 * and seller details). Not auto-assigned to any config path — select it under
 * Stores > Config > General > Design > Transactional Emails > Footer Template
 * if it should replace the theme default.
 */
class CreateFooterEmailTemplate implements DataPatchInterface
{
    private const TEMPLATE_CODE = 'FOOTER JP';
    private const ORIG_TEMPLATE = 'design_email_footer_template';

    private const SUBJECT = '{{trans "Footer"}}';

    private const BODY = <<<'HTML'
<!-- End Content -->
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
<table class="footer-wrapper" style="width: 100%;">
    <tr>
        <td class="footer">
            <table style="width: 100%; background-color: #4a4e53; color: #ffffff; text-align: center;" align="center">
                <tr>
                    <td style="padding: 30px 15px 10px;">
                        <span><a href="{{store direct_url='contact'}}" style="color:#ffffff;">お問い合わせ</a></span>
                         |
                        <span><a href="{{store direct_url='privacy-cookie-policy'}}" style="color:#ffffff;">プライバシー ポリシー</a></span>
                         |
                        <span><a href="{{store direct_url='terms-conditions'}}" style="color:#ffffff;">利用規約</a></span>
                         |
                        <span><a href="{{store direct_url='subete-no-jutan/about'}}" style="color:#ffffff;">会社概要</a></span>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 10px 15px 30px;">
                        <p>
                            販売業者: 株式会社 Carpet Avenue Japan<br>

業務執行取締役: 中川シャーロム<br>

所在地: 〒248-0022 神奈川県鎌倉市常盤362-4(店舗)<br>
お問合せ：info@carpetavenue.jp </p>
                    </td>
                </tr>
                {{depend is_newsletter}}
                    <tr>
                        <td style="padding: 0 15px 30px;" class="unsubscribe">
                            {{trans '<a href=%url_unsubscribe>ご購読は、弊社のウェブサイト、もしくはニュースレターに記載されているリンクから、いつでも解除できます。</a>' url_unsubscribe=$subscriber.getUnsubscriptionLink()|raw}}
                        </td>
                    </tr>
                {{/depend}}

            </table>
        </td>
    </tr>
</table>
<!-- End wrapper table -->
</body>
HTML;

    public function __construct(
        private ModuleDataSetupInterface $moduleDataSetup,
        private TemplateFactory $templateFactory,
        private TemplateResource $templateResource
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
