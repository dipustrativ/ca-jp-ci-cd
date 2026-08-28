<?php

declare(strict_types=1);

namespace Evalent\InvoicePaymentMods\Overwrite\Tax\Model\Sales\Pdf;

use Magento\Customer\Model\Vat;
use Magento\Framework\App\RequestInterface;

class Grandtotal extends \Magento\Tax\Model\Sales\Pdf\Grandtotal
{
    const SHOW_CUSTOM_GRAND_TOTAL_WITH_TAX_IN_INVOICE_PDF = 'payment/evinvoicepayment/show_grand_total_tax_invoice_pdf';

    /**
     * @var \Magento\Customer\Model\Vat
     */
    protected $vatHelper;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $request;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    public function __construct(
        RequestInterface $request,
        Vat $vatHelper,
        \Magento\Tax\Helper\Data $taxHelper,
        \Magento\Tax\Model\Calculation $taxCalculation,
        \Magento\Tax\Model\ResourceModel\Sales\Order\Tax\CollectionFactory $ordersFactory,
        \Magento\Tax\Model\Config $taxConfig,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        array $data = []
    ) {
        parent::__construct($taxHelper, $taxCalculation, $ordersFactory, $taxConfig, $data);
        $this->vatHelper = $vatHelper;
        $this->request = $request;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Check if tax amount should be included to grandtotals block
     * array(
     *  $index => array(
     *      'amount'   => $amount,
     *      'label'    => $label,
     *      'font_size'=> $font_size
     *  )
     * )
     * @return array
     */
    public function getTotalsForDisplay()
    {
        $store = $this->getOrder()->getStore();

        $displayGrandTotalTax = (bool)$this->scopeConfig->getValue(
            self::SHOW_CUSTOM_GRAND_TOTAL_WITH_TAX_IN_INVOICE_PDF,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $store
        );
        if (!$displayGrandTotalTax) {
            return \Magento\Sales\Model\Order\Pdf\Total\DefaultTotal::getTotalsForDisplay();
        }

        if (!$this->_taxConfig->displaySalesTaxWithGrandTotal($store)) {
            return parent::getTotalsForDisplay();
        }
        $amount = $this->getOrder()->formatPriceTxt($this->getAmount());
        $amountExclTax = $this->getAmount() - $this->getSource()->getTaxAmount();
        $amountExclTax = $amountExclTax > 0 ? $amountExclTax : 0;
        $amountExclTax = $this->getOrder()->formatPriceTxt($amountExclTax);
        $tax = $this->getOrder()->formatPriceTxt($this->getSource()->getTaxAmount());
        $fontSize = $this->getFontSize() ? $this->getFontSize() : 7;

        $totals = [];

        if ($this->_taxConfig->displaySalesFullSummary($store)) {
            $totals = array_merge($totals, $this->getFullTaxInfo());
        }
        if ($this->request->getParam("legacy")
            || ($this->getOrder()->getShippingAddress()->getCountryId() != "JP"
                && $this->getOrder()->getShippingAddress()->getCountryId() != "NO")
        ) {

            $grandTotalLabel = $this->request->getParam("legacy") ? 'Grand Total (Excl. Tax)' : __('CA - Grand Total (Excl. Tax)');
            $totals[] = [
                'amount' => $this->getAmountPrefix() . $amountExclTax,
                'label' =>  $grandTotalLabel . ':',
                'font_size' => $fontSize,
            ];
            $taxAmount = $this->taxTextToFloat($tax);
            $priceExclTaxAmount = $this->taxTextToFloat($amountExclTax);
            $taxPercentage = 0;
            if ($priceExclTaxAmount > 0.0) {
                $taxPercentage = round($taxAmount / $priceExclTaxAmount * 100, 1);
            }
            $taxLabel = __('Tax') == "Steuer" ? "MwSt." : __('Tax');
            if ($this->request->getParam("legacy")) {
                $taxLabel = "Steuer";
            }
            $taxPercentageLabel = $taxPercentage > 0 ?  " (" .  $taxPercentage . '%):' : ":";
            $totals[] = [
                'amount' => $this->getAmountPrefix() . $tax,
                'label' => $taxLabel . $taxPercentageLabel,
                'font_size' => $fontSize,
            ];
        }

        $grandTotalLabel = __('CA - Grand Total (Incl. Tax)') . ':';
        // We have to make a special string for Swiss orders as they will order from the german site but will have a custom label
        if ($this->getOrder()->getShippingAddress()->getCountryId() == "CH") {
            $grandTotalLabel = __('Grand Total') . ':';
        }

        $totals[] = [
            'amount' => $this->getAmountPrefix() . $amount,
            'label' => $grandTotalLabel,
            'font_size' => $fontSize,
        ];

        return $totals;
    }

    private function taxTextToFloat($text): float
    {
        return (float)preg_replace('/[^0-9]/', '',$text);
    }
}
