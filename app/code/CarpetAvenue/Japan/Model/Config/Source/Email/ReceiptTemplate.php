<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Japan\Model\Config\Source\Email;

use Magento\Config\Model\Config\Source\Email\Template as CoreTemplate;
use Magento\Email\Model\ResourceModel\Template\CollectionFactory;
use Magento\Email\Model\Template\Config as EmailConfig;
use Magento\Email\Model\Template\Config\UnexpectedTemplateIdValueException;
use Magento\Framework\Registry;

/**
 * Email-template source for the Evalent_InvoicePaymentMods receipt fields.
 *
 * Those fields sit in the `payment` section, which Magento\Paypal's StructurePlugin
 * clones into a per-country section for every country it supports
 * (payment_us, payment_jp, payment_it, ...). The core source model derives a
 * "(Default)" option id from the field path — payment_<cc>/evinvoicepayment/... —
 * and calls getTemplateLabel() on it, throwing UnexpectedTemplateIdValueException for
 * every country whose id is not declared, which crashes the entire Payment Methods
 * config page. Only the base `payment_evinvoicepayment_...` id is declared (see
 * CarpetAvenue/Japan/etc/email_templates.xml).
 *
 * Rather than enumerate PayPal's country list (which changes across upgrades), this
 * source drops the path for any clone whose default template is undefined, so the
 * throwing default-option branch is skipped while the base section keeps its default.
 */
class ReceiptTemplate extends CoreTemplate
{
    /**
     * @var EmailConfig
     */
    private $emailConfig;

    /**
     * @param Registry $coreRegistry
     * @param CollectionFactory $templatesFactory
     * @param EmailConfig $emailConfig
     * @param array $data
     */
    public function __construct(
        Registry $coreRegistry,
        CollectionFactory $templatesFactory,
        EmailConfig $emailConfig,
        array $data = []
    ) {
        parent::__construct($coreRegistry, $templatesFactory, $emailConfig, $data);
        $this->emailConfig = $emailConfig;
    }

    /**
     * @inheritDoc
     */
    public function toOptionArray()
    {
        $path = (string) $this->getPath();
        if ($path !== '') {
            $templateId = str_replace('/', '_', $path);
            try {
                $this->emailConfig->getTemplateLabel($templateId);
            } catch (UnexpectedTemplateIdValueException $e) {
                // Per-country payment clone with no declared default template —
                // drop the path so the core default-option branch is skipped.
                $this->setPath('');
            }
        }

        return parent::toOptionArray();
    }
}
