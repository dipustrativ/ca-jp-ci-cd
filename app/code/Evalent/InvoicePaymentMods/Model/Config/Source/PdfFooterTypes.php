<?php

declare(strict_types=1);

namespace Evalent\InvoicePaymentMods\Model\Config\Source;

class PdfFooterTypes implements \Magento\Framework\Data\OptionSourceInterface
{

    const INVOICE_PDF_NO_FOOTER = 0;
    const INVOICE_PDF_STYLE_FULL = 1;
    const INVOICE_PDF_STYLE_TRIPLE = 2;

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' => '<label>'), ...)
     */
    public function toOptionArray()
    {
        return [
            ["value" => self::INVOICE_PDF_NO_FOOTER, "label" => __("No Footer")],
            ["value" => self::INVOICE_PDF_STYLE_FULL, "label" => __("Full width")],
            ["value" => self::INVOICE_PDF_STYLE_TRIPLE, "label" => __("Three sections")],
        ];
    }
}