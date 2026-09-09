<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Setup\Patch\Data;

use Magento\Email\Model\Template;
use Magento\Email\Model\TemplateFactory;
use Magento\Email\Model\ResourceModel\Template as TemplateResource;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates the "Header JP" transactional email header template (localized nav
 * links). Not auto-assigned to any config path — select it under
 * Stores > Config > General > Design > Transactional Emails > Header Template
 * if it should replace the theme default.
 */
class CreateHeaderEmailTemplate implements DataPatchInterface
{
    private const TEMPLATE_CODE = 'Header JP';
    private const ORIG_TEMPLATE = 'design_email_header_template';

    private const SUBJECT = '{{trans "Header"}}';

    private const BODY = <<<'HTML'
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="viewport" content="initial-scale=1.0, width=device-width" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <style type="text/css">
        a {color: #575757;}
        .unsubscribe a,
        .unsubscribe a:visited {
            text-decoration: underline !important;
            color: #ffffff !important;
        }
    </style>
    <style type="text/css">
        {{var template_styles|raw}}

        {{css file="css/email.css"}}
    </style>
</head>
<body>
{{inlinecss file="css/email-inline.css"}}

<!-- Begin wrapper table -->
<table class="wrapper" style="width: 660px">
    <tr>
        <td class="wrapper-inner" align="center">
            <table class="main" align="center">
                <tr>
                    <td class="header" style="padding: 25px 0 25px 0; text-align: center">
                        <a class="logo" href="{{store url=''}}">
                            <img
                                {{if logo_width}}
                                    width="{{var logo_width}}"
                                {{else}}
                                    width="180"
                                {{/if}}

                                {{if logo_height}}
                                    height="{{var logo_height}}"
                                {{/if}}

                                src="{{var logo_url}}"
                                alt="{{var logo_alt}}"
                                border="0"
                            />
                        </a>
                    </td>
                </tr>
                <tr>
                    <table style="width:660px; text-align: center; font-size: 16px;" align="center">
                        <tr>
                            <!-- Menu items -->
                            <td style="width: 10%;">

                            </td>
                            <td style="width: 30%; padding: 0;">
                             <a href="{{store direct_url='subete-no-jutan/orientaru-ka-petto'}}" style="color:#333333;">オリエンタルカーペット</a>
                            </td>
                            <td style="width: 20%; padding: 0;">
                                <a href="{{store direct_url='subete-no-jutan/modan'}}" style="color:#333333;">モダンカーペット</a>
                            </td>
                            <td style="width: 20%; padding: 0;">
                                <a href="{{store direct_url='subete-no-jutan/se-ru'}}" style="color:#b32e27;">セール</a>
                            </td>
                            <td style="width: 10%;">

                            </td>
                        </tr>
                    </table>
                </tr>
                <tr>
                    <td class="main-content">
                    <!-- Begin Content -->
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
