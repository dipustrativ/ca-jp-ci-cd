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
 * Creates the "Catalog Template - JP" transactional email template (AjaxContact
 * Form catalogue request) and selects it in that module's config
 * (default scope).
 *
 *   ajaxcontactform/general/catalogue_email = <new template id>
 */
class CreateCatalogueEmailTemplate implements DataPatchInterface
{
    private const TEMPLATE_CODE = 'Catalog Template - JP';
    private const ORIG_TEMPLATE = 'ajaxcontactform_general_catalogue_email';
    private const CONFIG_PATH = 'ajaxcontactform/general/catalogue_email';

    private const SUBJECT = 'ニューカタログ 2026';

    private const STYLES = 'body,td { color:#2f2f2f; font:11px/1.35em Verdana, Arial, Helvetica, sans-serif; }';

    private const ORIG_VARIABLES = <<<'JSON'
{  "store url=\"\"":"Store Url",  "skin url=\"images/logo_email.gif\" _area='frontend'":"Email Logo Image",  "store url=\"customer/account/\"":"Customer Account Url",  "htmlescape var=$customer.name":"Customer Name",  "var customer.email":"Customer Email",  "store url=\"customer/account/confirm/\" _query_id=$customer.id _query_key=$customer.confirmation _query_back_url=$back_url":"Confirmation Url",  "htmlescape var=$customer.password":"Customer Password",  "var logo_url":"Email Logo Image URL",  "var logo_alt":"Email Logo Alt Text",  "var logo_height":"Email Logo Image Height",  "var logo_width":"Email Logo Image Width",  "var surname": "Customer Surname",  "var name": "Customer First Name",  "var email": "Customer Email",  "var zip": "Postal Code",  "var address": "Address",  "var phone": "Phone Number"}
JSON;

    private const BODY = <<<'HTML'
<body style="background:#F6F6F6; font-family:Verdana, Arial, Helvetica, sans-serif; font-size:12px; margin:0; padding:0;">
<div style="background:#F6F6F6; font-family:Verdana, Arial, Helvetica, sans-serif; font-size:12px; margin:0; padding:0;">
    <table cellspacing="0" cellpadding="0" border="0" height="100%" width="100%">
        <tr>
            <td align="center" valign="top" style="padding:20px 0 20px 0">
                <!-- [ header starts here] -->
                <table bgcolor="FFFFFF" cellspacing="0" cellpadding="10" border="0" width="650" style="border:1px solid #E0E0E0;">
                    <tr>
                        <td valign="top">
                            <a class="logo" href="{{store url=""}}">
                            <img
                                    {{if logo_width}} width="{{var logo_width}}" {{else}} width="180" {{/if}}
                            {{if logo_height}} height="{{var logo_height}}" {{/if}}
                            src="{{var logo_url}}"
                            alt="{{var logo_alt}}"
                            border="0"
                            />
                            </a>
                        </td>
                    </tr>
                    <!-- [ middle starts here] -->
                    <tr>
                        <td valign="top">
                            <h1 style="font-size:22px; font-weight:normal; line-height:22px; margin:0 0 11px 0;">ニューカタログ 2026</h1>
                            {{if surname}}<strong>{{trans "Surname"}}:</strong> <span>{{var surname}}</span><br/>{{/if}}
                            {{if name}}<strong>{{trans "First Name"}}:</strong> <span>{{var name}}</span><br/>{{/if}}
                            {{if email}}<strong>{{trans "Email"}}:</strong> <span>{{var email}}</span><br/>{{/if}}
                            {{if zip}}<strong>{{trans "Postal Code"}}:</strong> <span>{{var zip}}</span><br/>{{/if}}
                            {{if address}}<strong>{{trans "Address"}}:</strong> <span>{{var address}}</span><br/>{{/if}}
                            {{if phone}}<strong>{{trans "Phone"}}:</strong> <span>{{var phone}}</span><br/>{{/if}}
                        </td>
                    </tr>
                    <tr>
                        <td bgcolor="#EAEAEA" align="center" style="background:#EAEAEA; text-align:center;">
                            <center>
                                <p style="font-size:12px; margin:0;">
                                    {{trans "Thank you"}}
                                </p>
                            </center>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</div>
</body>
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
                ->setTemplateStyles(self::STYLES)
                ->setTemplateSubject(self::SUBJECT)
                ->setTemplateType(Template::TYPE_HTML)
                ->setOrigTemplateCode(self::ORIG_TEMPLATE)
                ->setOrigTemplateVariables(self::ORIG_VARIABLES);
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
