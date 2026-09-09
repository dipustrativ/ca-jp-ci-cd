<?php

declare(strict_types=1);

namespace Evalent\InvoicePaymentMods\Overwrite\Plugin;

use Evalent\InvoicePaymentMods\Model\Config\Source\PdfFooterTypes;
use Magento\Customer\Model\Vat;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Module\Dir\Reader;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Invoice\Collection;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\ScopeInterface;

class Invoice extends \Evalent\InvoicePaymentMods\Plugin\Invoice
{

    protected $ignoreTax = false;

    protected $invoiceStoreId;

    protected $invoiceLanguage;

    protected $hasFooter;

    /**
     * @var \Magento\Framework\Module\Dir\Reader
     */
    protected $moduleReader;

    /**
     * @var \Magento\Customer\Model\Vat
     */
    protected $vatHelper;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $request;

    private $pageSettings;

    /**
     * @var Database
     */
    private $fileStorageDatabase;

    /**
     * @var \Magento\Store\Model\App\Emulation
     */
    private $emulation;

    /**
     * @var \Magento\Tax\Model\Calculation\Rate
     */
    private $_rate;

    /**
     * @var \Magento\Sales\Model\Order\Invoice
     */
    private $currentInvoice;

    /**
     * @var \Magento\Tax\Helper\Data
     */
    private $_taxHelper;

    /**
     * @var \Magento\InventorySourceDeductionApi\Model\GetSourceItemBySourceCodeAndSku
     */
    private $getSourceItemBySourceCodeAndSku;
    private \Magento\Framework\Locale\ResolverInterface $localeResolver;

    public function __construct(
        RequestInterface $request,
        Vat $vatHelper,
        Reader $moduleReader,
        Emulation $emulation,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\Stdlib\StringUtils $string,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Sales\Model\Order\Pdf\Config $pdfConfig,
        \Magento\Sales\Model\Order\Pdf\Total\Factory $pdfTotalFactory,
        \Magento\Sales\Model\Order\Pdf\ItemsFactory $pdfItemsFactory,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Magento\Framework\Translate\Inline\StateInterface $inlineTranslation,
        \Magento\Sales\Model\Order\Address\Renderer $addressRenderer,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Locale\ResolverInterface $localeResolver,
        \Magento\Tax\Model\Calculation\Rate $rate,
        \Magento\Tax\Helper\Data $taxHelper,
        \Magento\InventorySourceDeductionApi\Model\GetSourceItemBySourceCodeAndSku $getSourceItemBySourceCodeAndSku,
        array $data = [],
        ?Database $fileStorageDatabase = null
    ) {
        parent::__construct(
            $paymentData,
            $string,
            $scopeConfig,
            $filesystem,
            $pdfConfig,
            $pdfTotalFactory,
            $pdfItemsFactory,
            $localeDate,
            $inlineTranslation,
            $addressRenderer,
            $storeManager,
            $emulation,
            $data
        );

        $this->fileStorageDatabase = $fileStorageDatabase ?:
            \Magento\Framework\App\ObjectManager::getInstance()->get(Database::class);
        $this->emulation = $emulation;
        $this->_rate = $rate;
        $this->_taxHelper = $taxHelper;
        $this->moduleReader = $moduleReader;
        $this->vatHelper = $vatHelper;
        $this->request = $request;
        $this->getSourceItemBySourceCodeAndSku = $getSourceItemBySourceCodeAndSku;
        $this->localeResolver = $localeResolver;
    }


    /**
     * Set font as regular
     *
     * @param  \Zend_Pdf_Page $object
     * @param  int $size
     * @return \Zend_Pdf_Resource_Font
     */
    protected function setJapaneseFont($object, $size = 7): \Zend_Pdf_Resource_Font
    {
        $font = \Zend_Pdf_Font::fontWithPath(
            $this->_rootDirectory->getAbsolutePath('lib/internal/GnuFreeFont/cid0jp.ttf')
        );
        $object->setFont($font, $size);
        return $font;
    }

    /**
     * @return bool
     */
    public function ignoreTax(): bool
    {
        return $this->ignoreTax;
    }

    /**
     * Draw header for item table
     *
     * @param \Zend_Pdf_Page $page
     * @return void
     */
    protected function _drawHeader(\Zend_Pdf_Page $page)
    {
        /* Add table head */
        $this->_setFontRegular($page, 10);

        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0.93, 0.92, 0.92));
        $page->setLineColor(new \Zend_Pdf_Color_GrayScale(0.5));
        $page->setLineWidth(0.5);
        $page->drawRectangle(30, $this->y, 565, $this->y - 15);
        $this->y -= 10;
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0, 0, 0));

        //columns headers
        $lines[0][] = ['text' => __('Products'), 'feed' => 35];
        $lines[0][] = ['text' => __('CA - SKU'), 'feed' => 290, 'align' => 'right'];
        $lines[0][] = ['text' => __('CA - Qty'), 'feed' => 430, 'align' => 'right'];
        $lines[0][] = ['text' => __('Price'), 'feed' => 370, 'align' => 'right'];
        if (!$this->ignoreTax) {
            $lines[0][] = ['text'  => __('CA - Tax'),
                           'feed'  => 490,
                           'align' => 'right'
            ];
        }

        $subTotalLabel = __('Subtotal');
        if (strtolower($this->invoiceLanguage) == "dk") {
            $subTotalLabel = "Delsum";
        }
        $lines[0][] = ['text' => $subTotalLabel, 'feed' => 560, 'align' => 'right'];

        $lineBlock = ['lines' => $lines, 'height' => 5];

        $this->drawLineBlocks($page, [$lineBlock], ['table_header' => true]);
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
        $this->y -= 20;
    }

    /**
     * Return PDF document
     *
     * @param array|Collection $invoices
     * @return \Zend_Pdf
     */
    public function getPdf($invoices = [])
    {
        $this->_beforeGetPdf();
        $this->_initRenderer('invoice');

        $pdf = new \Zend_Pdf();
        $this->_setPdf($pdf);
        $style = new \Zend_Pdf_Style();
        $this->_setFontBold($style, 10);

        /** @var \Magento\Sales\Model\Order\Invoice $invoice */
        foreach ($invoices as $invoice) {
            $this->invoiceStoreId = 0;
            $this->invoiceLanguage = "ENG";
            $this->currentInvoice = $invoice;
            if ($this->request->getParam('canceled') && !$invoice->isCanceled()) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __("The invoice %1 is not canceled", $invoice->getIncrementId())
                );
            }
            if ($invoice->getStoreId()) {
                $this->emulation->startEnvironmentEmulation($invoice->getStoreId());
                $this->_storeManager->setCurrentStore($invoice->getStoreId());
                $this->invoiceLanguage = $this->_storeManager->getStore()->getCode();
                $this->invoiceStoreId = $invoice->getStore();
                if ($this->request->getParam("legacy")
                    || ($invoice->getOrder()->getShippingAddress()->getCountryId() != "JP"
                        && $invoice->getOrder()->getShippingAddress()->getCountryId() != "NO")
                ) {
                    $this->ignoreTax = false;
                } else {
                    $this->ignoreTax = true;
                }
            }

            if ($invoice->getStoreId() && $this->localeResolver->getLocale() != $this->invoiceLanguage) {
                $this->localeResolver->emulate($invoice->getStoreId());
            }

            $page = $this->newPage();
            $order = $invoice->getOrder();
            /* Add image */
            $this->insertLogo($page, $invoice->getStore());
            /* Add address */
            $this->insertAddress($page, $invoice->getStore());
            /* Add head */
            $this->insertOrder(
                $page,
                $order,
                $this->_scopeConfig->isSetFlag(
                    self::XML_PATH_SALES_PDF_INVOICE_PUT_ORDER_ID,
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $order->getStoreId()
                )
            );

            ///////////////////////////////
            ///////////////////////////////

            $yText2 = $yText1 = $this->y -= 10;
            $text1 = $this->_scopeConfig->getValue('payment/evinvoicepayment/invoice_villkor');
            $text2 = $this->_scopeConfig->getValue('payment/evinvoicepayment/invoice_referens');
            if (($text1 && trim($text1) != '') || ($text2 && trim($text2) != '')) {
                $textChunk = wordwrap($text1, 45, "\n", true);
                foreach (explode("\n", $textChunk) as $textLine) {
                    if ($textLine !== '') {
                        $yText1 -= 14;
                    }
                }
                $textChunk = wordwrap($text2, 45, "\n", true);
                foreach (explode("\n", $textChunk) as $textLine) {
                    if ($textLine !== '') {
                        $yText2 -= 14;
                    }
                }
                $yFinal = ($yText1 <= $yText2) ? $yText1 : $yText2;
                $page->setFillColor(new \Zend_Pdf_Color_Rgb(0.93, 0.92, 0.92));
                $page->setLineWidth(0.5);
                $page->drawRectangle(30, $this->y + 15, 275, $yFinal - 10);
                $page->drawRectangle(275, $this->y + 15, 565, $yFinal - 10);
                $yText2 = $yText1 = $this->y -= 10;
                $this->_setFontRegular($page, 8);
                $textChunk = wordwrap($text1, 45, "\n", true);
                foreach (explode("\r\n", $textChunk) as $textLine) {
                    if ($textLine !== '') {
                        $this->_setFontBold($page, 8);
                        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
                        $page->drawText(strip_tags(ltrim($textLine)), 30, $yText1, 'UTF-8');
                        $yText1 -= 14;
                    }
                }
                $textChunk = wordwrap($text2, 45, "\n", true);
                foreach (explode("\r\n", $textChunk) as $textLine) {
                    if ($textLine !== '') {
                        $this->_setFontBold($page, 8);
                        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
                        $page->drawText(strip_tags(ltrim($textLine)), 280, $yText2, 'UTF-8');
                        $yText2 -= 14;
                    }
                }
                $this->y = $yFinal - 25;
            }

            // $customerEmail = $order->getCustomerEmail();
            // $page->drawRectangle(30, $this->y + 5, 565, $this->y - 20, \Zend_Pdf_Page::SHAPE_DRAW_STROKE);

            $this->_setFontBold($page, 14);
            $page->drawText(__("Order Summary"), 30, $this->y - 10, 'UTF-8');
            $this->_setFontRegular($page, 10);
            $this->y -= 30;
            /// ///////////////////////////////
            /// ///////////////////////////////
            /* Add document text and number */
            if (!$this->request->getParam('canceled')) {
                $this->insertDocumentNumber($page, __('Invoice: ') . $invoice->getIncrementId());//#
            } else {
                $this->insertDocumentNumber($page, __('Cancellation invoice'));//#
            }
            /* Add table */
            $this->_drawHeader($page);
            /* Add body */
            foreach ($invoice->getAllItems() as $item) {
                if ($item->getOrderItem()->getParentItem()) {
                    continue;
                }
                /* Draw item */
                $this->_drawItem($item, $page, $order);
                $page = end($pdf->pages);
            }
            /* Add totals */
            $this->insertTotals($page, $invoice);
            if ($order->getShippingAddress()->getCountryId() == "CH") {
                $lineBlock['lines'][][] = [
                    'text'      => __("All fees, including handling and customs where it applies, are included in the price."),
                    'feed'      => 560,
                    'align'     => 'right',
                ];
                $lineBlock['lines'][][] = [
                    'text'      => __("The indicated price is VAT inclusive."),
                    'feed'      => 560,
                    'align'     => 'right',
                ];


                $this->y -= 20;
                $page = $this->drawLineBlocks($page, [$lineBlock]);
                $lineBlock = [];
            }
            if ($order->getShippingAddress()->getCountryId() == "NO") {
                $lineBlock['lines'][][] = [
                    'text'      => __("PRISEN ER EKS. TOLLGEBYR."),
                    'feed'      => 560,
                    'align'     => 'right',
                ];
                $lineBlock['lines'][][] = [
                    'text'      => __("En faktura med norsk tollgebyr betales separat til fraktselskapet."),
                    'feed'      => 560,
                    'align'     => 'right',
                ];

                $this->y -= 20;
                $page = $this->drawLineBlocks($page, [$lineBlock]);
                $lineBlock = [];
            }
            if ($invoice->getStoreId()) {
                $this->localeResolver->revert();
            }
            $this->emulation->stopEnvironmentEmulation();
        }

        $page->rotate(20, 20, 1.57);
        $invoice_virtical = $this->_scopeConfig->getValue('payment/evinvoicepayment/invoice_virtical');
        if ($invoice_virtical && trim($invoice_virtical) != '') {
            $page->drawText($invoice_virtical, 300, 20);
        }

        $this->_afterGetPdf();
        return $pdf;
    }

    protected function _drawFooter(\Zend_Pdf_Page $page)
    {
        $this->hasFooter = false;
        $pdfStyle = $this->_scopeConfig->getValue('payment/evinvoicepayment/pdf_footer_type', ScopeInterface::SCOPE_STORE, $this->invoiceStoreId);
        switch ($pdfStyle) {
            case PdfFooterTypes::INVOICE_PDF_STYLE_FULL:
                $text = $this->_scopeConfig->getValue('payment/evinvoicepayment/pdf_footer_full', ScopeInterface::SCOPE_STORE, $this->invoiceStoreId);
                $chunk = explode("\r\n", wordwrap($text, 180, "\r\n", true));
                $footerY = 20;
                $this->_setFontRegular($page, 10);
                foreach (array_reverse($chunk) as $key => $textLine) {
                    if ($key == sizeof($chunk) - 1) {
                        $this->_setFontBold($page, 10);
                    }
                    $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
                    $page->drawText(strip_tags(ltrim($textLine)), 30, $footerY, 'UTF-8');
                    $footerY += 10;
                }
                $this->hasFooter = true;
                break;
            case PdfFooterTypes::INVOICE_PDF_STYLE_TRIPLE:
                $left = $this->_scopeConfig->getValue('payment/evinvoicepayment/pdf_footer_left', ScopeInterface::SCOPE_STORE, $this->invoiceStoreId);
                $middle = $this->_scopeConfig->getValue('payment/evinvoicepayment/pdf_footer_middle', ScopeInterface::SCOPE_STORE, $this->invoiceStoreId);
                $right = $this->_scopeConfig->getValue('payment/evinvoicepayment/pdf_footer_right', ScopeInterface::SCOPE_STORE, $this->invoiceStoreId);

                $leftChunks = explode("\r\n", $left ?? "");
                $middleChunks = explode("\r\n", $middle ?? "");
                $rightChunks = explode("\r\n", $right ?? "");
                $maxHeight = max(sizeof($leftChunks), sizeof($middleChunks), sizeof($rightChunks));
                $footerY = 20 + (($maxHeight - sizeof($leftChunks)) * 10);
                $this->_setFontRegular($page, 10);
                foreach (array_reverse($leftChunks) as $key => $textLine) {
                    if ($key == sizeof($leftChunks) - 1) {
                        $this->_setFontBold($page, 10);
                    }
                    $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
                    $page->drawText(strip_tags(ltrim($textLine)), 30, $footerY, 'UTF-8');
                    $footerY += 10;
                }
                $footerY = 20 + (($maxHeight - sizeof($middleChunks)) * 10);
                $this->_setFontRegular($page, 10);
                foreach (array_reverse($middleChunks) as $key => $textLine) {
                    if ($key == sizeof($middleChunks) - 1) {
                        $this->_setFontBold($page, 10);
                    }
                    $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
                    $page->drawText(strip_tags(ltrim($textLine)), 210, $footerY, 'UTF-8');
                    $footerY += 10;
                }
                $footerY = 20 + (($maxHeight - sizeof($rightChunks)) * 10);
                $this->_setFontRegular($page, 10);
                foreach (array_reverse($rightChunks) as $key => $textLine) {
                    if ($key == sizeof($rightChunks) - 1) {
                        $this->_setFontBold($page, 10);
                    }
                    $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
                    $page->drawText(strip_tags(ltrim($textLine)), 390, $footerY, 'UTF-8');
                    $footerY += 10;
                }
                $this->_setFontRegular($page, 10);
                $this->hasFooter = true;
                break;
        }
    }

    /**
     * Create new page and assign to PDF object
     *
     * @param  array $settings
     * @return \Zend_Pdf_Page
     */
    public function newPage(array $settings = [])
    {
        /* Add new table head */
        $page = $this->_getPdf()->newPage(\Zend_Pdf_Page::SIZE_A4);
        $this->_getPdf()->pages[] = $page;
        $this->y = 800;
        if (!empty($settings['table_header'])) {
            $this->_drawHeader($page);
        }
        $this->_drawFooter($page);
        return $page;
    }

    /**
     * @param \Magento\Sales\Model\Order\Invoice $invoice
     * @return bool
     */
    private function isAllProductsInJapanSource($invoice)
    {
        /** @var \Magento\Sales\Api\Data\InvoiceItemInterface $item */
        foreach ($invoice->getAllItems() as $item) {
            try {
                $sourceItem = $this->getSourceItemBySourceCodeAndSku->execute('tokyo', $item->getSku());
                if (!($sourceItem->getStatus() == SourceItemInterface::STATUS_IN_STOCK &&
                    $sourceItem->getQuantity() > 0)) {
                    return false;
                }
            } catch (\Magento\Framework\Exception\NoSuchEntityException $exception) {
                return false;
            }
        }
        return true;
    }

    /**
     * Insert order to pdf page
     *
     * @param \Zend_Pdf_Page &$page
     * @param \Magento\Sales\Model\Order $obj
     * @param bool $putOrderId
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function insertOrder(&$page, $obj, $putOrderId = true)
    {
        if ($obj instanceof \Magento\Sales\Model\Order) {
            $shipment = null;
            $order = $obj;
        } elseif ($obj instanceof \Magento\Sales\Model\Order\Shipment) {
            $shipment = $obj;
            $order = $shipment->getOrder();
        }

        $this->y = $this->y ? $this->y : 815;
        $top = $this->y;

        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0.45));
        $page->setLineColor(new \Zend_Pdf_Color_GrayScale(0.45));
        // $page->drawRectangle(30, $top, 565, $top - 55);
        //$page->setFillColor(new \Zend_Pdf_Color_GrayScale(1));
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0, 0, 0));
        $this->setDocHeaderCoordinates([30, $top, 565, $top - 55]);
        //        $this->_setFontRegular($page, 14);
        $this->_setFontRegular($page, 14);

        if ($putOrderId) {
            $page->drawText(__('Order: ') . $order->getRealOrderId(), 30, $top + 15, 'UTF-8');#
            $top += 20;
        }

        $top -= 30;
        $invoiceDate = $this->currentInvoice->getCreatedAt();
        if ($this->request->getParam('canceled')) {
            $invoiceDate = $this->currentInvoice->getUpdatedAt();
        }
        $page->drawText(
            __('Invoice Date: ') .
            $this->_localeDate->formatDate(
                $this->_localeDate->scopeDate(
                    $order->getStore(),
                    $invoiceDate,
                    true
                ),
                \IntlDateFormatter::MEDIUM,
                false
            ),
            30,
            $top -5,
            'UTF-8'
        );

        $customerNumberLabel = __('Customer Number: ');
        if (strtolower($order->getStore()->getCode()) == "nl") {
            $customerNumberLabel = "Klantnummer: ";
        }

        $page->drawText(
            $customerNumberLabel . $order->getCustomerId(),
            30,
            $top +10,
            'UTF-8'
        );

        //Here we add some coditional text. $condTextHeight is used for the needed height if the area
        $condTextHeight = 40;
        $this->_setFontRegular($page, 12);
        // As of ticket CA-483, this text should be visible on all invoices
        // As of ticket ECA-135, this text should be visible on all invoices EXCEPT for JP (store id 17)
        if ($order->getStoreId() != 17) {
        $page->drawText(
            __('Rechnungsdatum entspricht dem Leistungs-/Lieferdatum.'),
            30,
            $top - $condTextHeight,
            'UTF-8'
        );
        $condTextHeight += 15;
        }
        // if invoice is canceled we need to add this line
        if ($this->request->getParam('canceled')) {
            $page->drawText(
                __('Storno-Rechnung zu Rechnungsnummer: %1', $this->currentInvoice->getIncrementId()),
                30,
                $top - $condTextHeight,
                'UTF-8'
            );
            $condTextHeight += 15;
        }

//         if order contains VAT And is in EU show required line
        if (($order->getCustomerTaxvat() != "" || $order->getBillingAddress()->getVatId() != "")
            && $this->vatHelper->isCountryInEU($order->getShippingAddress()->getCountryId())
            && $order->getShippingAddress()->getCountryId() != "DE") {
            $page->drawText(
                __('Steuerfreie innergemeinschaftliche Lieferung'),
                30,
                $top - $condTextHeight,
                'UTF-8'
            );
            $condTextHeight += 15;
        } else if ($order->getShippingAddress()->getCountryId() == "JP") {
            // Don't add the 'export product' phrase ('Steuerfreie Ausfuhrlieferung')
        } else if (
            !$this->vatHelper->isCountryInEU($order->getShippingAddress()->getCountryId()) && $order->getShippingAddress()->getCountryId() != "CH"
            || $order->getTaxAmount() == 0
        ) {
            $page->drawText(
                __('Steuerfreie Ausfuhrlieferung'),
                30,
                $top - $condTextHeight,
                'UTF-8'
            );
            $condTextHeight += 15;
        }
        $this->_setFontBold($page, 14);

        $top -= $condTextHeight;
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0.93, 0.92, 0.92));
        $page->setLineColor(new \Zend_Pdf_Color_GrayScale(0.5));
        $page->setLineWidth(0.5);
        $page->drawRectangle(30, $top -5, 275, $top - 35);
        $page->drawRectangle(275, $top -5, 565, $top - 35);

        /* Calculate blocks info */

        /* Billing Address */
        $billingAddress = $this->_formatAddress($this->addressRenderer->format($order->getBillingAddress(), 'pdf'));

        /* Shipping Address and Method */
        if (!$order->getIsVirtual()) {
            /* Shipping Address */
            $shippingAddress = $this->_formatAddress($this->addressRenderer->format($order->getShippingAddress(), 'pdf'));
            $shippingMethod = $order->getShippingDescription();
            $shippingMethod = preg_replace('/\((.*?)\)/', '',  $shippingMethod);
            if (in_array($order->getShippingAddress()->getCountryId(), ['CH', 'GB'])) {
                $shippingMethod .= " - DDP";
            }
        }

        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
        $this->_setFontBold($page, 12);
        $soldToLabel = __('CA - Sold to:');
        if (strtolower($order->getStore()->getCode()) == "se") {
            $soldToLabel = "Såld till:";
        }
        $page->drawText($soldToLabel, 35, $top - 20, 'UTF-8');

        if (!$order->getIsVirtual()) {
            $page->drawText(__('CA - Ship to:'), 285, $top - 20, 'UTF-8');
        } else {
            $page->drawText(__('Payment Method:'), 285, $top - 20, 'UTF-8');
        }

        $addressesHeight = $this->_calcAddressHeight($billingAddress);
        if (isset($shippingAddress)) {
            $addressesHeight = max($addressesHeight, $this->_calcAddressHeight($shippingAddress));
        }

        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(1));
        $page->drawRectangle(30, $top - 30, 565, $top - 38 - $addressesHeight);
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
        $this->_setFontRegular($page, 10);
        $this->y = $top - 45;
        $addressesStartY = $this->y;

        foreach ($billingAddress as $value) {
            if ($value !== '') {
                $text = [];
                foreach ($this->string->split($value, 45, true, true) as $_value) {
                    $text[] = $_value;
                }
                foreach ($text as $part) {
                    $page->drawText(strip_tags(ltrim($part)), 35, $this->y, 'UTF-8');
                    $this->y -= 15;
                }
            }
        }

        $addressesEndY = $this->y;

        if (!$order->getIsVirtual()) {
            $this->y = $addressesStartY;
            foreach ($shippingAddress as $value) {
                if ($value !== '') {
                    $text = [];
                    foreach ($this->string->split($value, 45, true, true) as $_value) {
                        $text[] = $_value;
                    }
                    foreach ($text as $part) {
                        $page->drawText(strip_tags(ltrim($part)), 285, $this->y, 'UTF-8');
                        $this->y -= 15;
                    }
                }
            }

            $addressesEndY = min($addressesEndY, $this->y);
            $this->y = $addressesEndY;

            $page->setFillColor(new \Zend_Pdf_Color_Rgb(0.93, 0.92, 0.92));
            $page->setLineWidth(0.5);
            $page->drawRectangle(30, $this->y, 275, $this->y - 25);
            $page->drawRectangle(275, $this->y, 565, $this->y - 25);

            $this->y -= 15;
            $this->_setFontBold($page, 12);
            $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
            $page->drawText(__('Payment Method:'), 35, $this->y, 'UTF-8');
            $page->drawText(__('Shipping Method:'), 285, $this->y, 'UTF-8');

            $this->y -= 10;
            $page->setFillColor(new \Zend_Pdf_Color_GrayScale(1));

            $this->_setFontRegular($page, 10);
            $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));

            $paymentLeft = 35;
            $yPayments = $this->y - 15;
        } else {
            $yPayments = $addressesStartY;
            $paymentLeft = 285;
        }

        $payment = $order->getPayment();
        $method = $payment->getMethodInstance();
        $methodTitle = $method->getTitle();

        $brandCode = $order->getPayment()->getAdditionalInformation()['brand_code'] ?? null;

        if ($brandCode) {
            $page->drawText(strip_tags(trim($brandCode)), $paymentLeft, $yPayments, 'UTF-8');
        } else {
            $page->drawText(strip_tags(trim($methodTitle)), $paymentLeft, $yPayments, 'UTF-8');
        }

        $yPayments -= 15;

        if ($order->getIsVirtual()) {
            // replacement of Shipments-Payments rectangle block
            $yPayments = min($addressesEndY, $yPayments);
            $page->drawLine(30, $top - 30, 30, $yPayments);
            $page->drawLine(565, $top - 30, 565, $yPayments);
            $page->drawLine(30, $yPayments, 565, $yPayments);

            $this->y = $yPayments - 15;
        } else {
            $topMargin = 15;
            $methodStartY = $this->y;
            $this->y -= 15;

            foreach ($this->string->split($shippingMethod, 45, true, true) as $_value) {
                $page->drawText(strip_tags(trim($_value)), 285, $this->y, 'UTF-8');
                $this->y -= 15;
            }

            $yShipments = $this->y;

            $tracks = [];
            if ($shipment) {
                $tracks = $shipment->getAllTracks();
            }
            if (count($tracks)) {
                $page->setFillColor(new \Zend_Pdf_Color_Rgb(0.93, 0.92, 0.92));
                $page->setLineWidth(0.5);
                $page->drawRectangle(285, $yShipments, 510, $yShipments - 10);
                $page->drawLine(400, $yShipments, 400, $yShipments - 10);
                //$page->drawLine(510, $yShipments, 510, $yShipments - 10);

                $this->_setFontRegular($page, 9);
                $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
                //$page->drawText(__('Carrier'), 290, $yShipments - 7 , 'UTF-8');
                $page->drawText(__('Title'), 290, $yShipments - 7, 'UTF-8');
                $page->drawText(__('Number'), 410, $yShipments - 7, 'UTF-8');

                $yShipments -= 20;
                $this->_setFontRegular($page, 8);
                foreach ($tracks as $track) {
                    $maxTitleLen = 45;
                    $endOfTitle = strlen($track->getTitle()) > $maxTitleLen ? '...' : '';
                    $truncatedTitle = substr($track->getTitle(), 0, $maxTitleLen) . $endOfTitle;
                    $page->drawText($truncatedTitle, 292, $yShipments, 'UTF-8');
                    $page->drawText($track->getNumber(), 410, $yShipments, 'UTF-8');
                    $yShipments -= $topMargin - 5;
                }
            } else {
                $yShipments -= $topMargin - 5;
            }

            $currentY = min($yPayments, $yShipments);

            // replacement of Shipments-Payments rectangle block
            $page->drawLine(30, $methodStartY, 30, $currentY);
            //left
            $page->drawLine(30, $currentY, 565, $currentY);
            //bottom
            $page->drawLine(565, $currentY, 565, $methodStartY);
            //right

            $this->y = $currentY;
            $this->y -= 15;
        }
    }

    /**
     * Insert title and number for concrete document type
     *
     * @param  \Zend_Pdf_Page $page
     * @param  string $text
     * @return void
     */
    public function insertDocumentNumber(\Zend_Pdf_Page $page, $text)
    {
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(1));
        //        $this->_setFontRegular($page, 14);
        $this->_setFontRegular($page, 14);
        $docHeader = $this->getDocHeaderCoordinates();
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0, 0, 0));
        $page->drawText($text, 30, $docHeader[1] +30, 'UTF-8');
    }

    /**
     * @param  \Magento\Sales\Model\AbstractModel $source
     */
    private function _getTaxTitle($source)
    {
        $data = $this->_taxHelper->getCalculatedTaxes($source);
        if (isset($data[0]['title'])) {
            return $data[0]['title'];
        }

        return __("Tax");
    }

    /**
     * Add message to Japanese invoices
     *
     * @param array $lineBlock
     * @return array
     */
    private function addJapaneseMessage(array $lineBlock): array
    {
        $lineBlock['lines'][] = [
            [
                'text' => __('Japanese Additional Invoice Message'),
                'feed' => 560,
                'align' => 'right',
                'font_size' => 8,
                'font' => 'bold',
            ],
        ];

        return $lineBlock;
    }

    /**
     * Insert totals to pdf page
     *
     * @param \Zend_Pdf_Page $page
     * @param \Magento\Sales\Model\AbstractModel $source
     * @return \Zend_Pdf_Page
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Zend_Pdf_Exception
     */
    protected function insertTotals($page, $source)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $source->getOrder();
        $totals = $this->_getTotalsList();
        $lineBlock = ['lines' => [], 'height' => 15];
        foreach ($totals as $total) {
            $total->setOrder($order)->setSource($source);

            $amountPrefix = "";
            if ($this->request->getParam('canceled')) {
                $amountPrefix = "- ";
            }


            if ($total->canDisplay()) {
                $total->setFontSize(10);
                foreach ($total->getTotalsForDisplay() as $totalData) {
                    if ($this->invoiceLanguage === 'dk' && $totalData['label'] === 'Delbeløb:') {
                        $totalData['label'] = 'Delsum:';
                    }

                    // Improve tax title handling

                    if (in_array($totalData['label'], ['ges MwSt.:', 'Tax:', __('Tax')])) {
                        $taxTitle = $this->_getTaxTitle($source);
                        $totalData['label'] = $taxTitle . ':';
                    }

                    $lineBlock['lines'][] = [
                        [
                            'text' => $totalData['label'],
                            'feed' => 475,
                            'align' => 'right',
                            'font_size' => $totalData['font_size'],
                            'font' => 'bold'
                        ],
                        [
                            'text' => $amountPrefix . $totalData['amount'],
                            'feed' => 560,
                            'align' => 'right',
                            'font_size' => $totalData['font_size'],
                            'font' => 'bold',
                        ],
                    ];
                }
            }


        }

        if ($this->invoiceLanguage === 'jp') {
            $lineBlock = $this->addJapaneseMessage($lineBlock);
        }

        $this->y -= 20;
        $page = $this->drawLineBlocks($page, [$lineBlock]);

        $invoiceExtraDetails = $this->_scopeConfig->getValue('payment/evinvoicepayment/invoice_extra_details');
        if ($invoiceExtraDetails && trim($invoiceExtraDetails) != '') {
            $fontSize = 6;
            $y = $this->y;
            $textChunk = wordwrap($invoiceExtraDetails, 180, "\n", true);
            $page->setFont(\Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA), $fontSize);
            $width = $page->getWidth();
            $page->setLineWidth(0.5);
            $lines = $y;

            foreach (explode("\n", $textChunk) as $textLine) {
                if ($textLine !== '') {
                    $page->
                    drawText(strip_tags(ltrim($textLine)), 30, $lines - 20, 'UTF-8');
                    $lines -= 14;
                }
            }
            $page->
            drawRectangle(30, $y - 10, $width - 30, $lines - 14, \Zend_Pdf_Page::SHAPE_DRAW_STROKE);
        }
        return $page;
    }

    /**
     * Insert logo to pdf page
     *
     * @param \Zend_Pdf_Page $page
     * @param string|null $store
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @throws \Zend_Pdf_Exception
     */
    protected function insertLogo(&$page, $store = null)
    {
        $this->y = $this->y ? $this->y : 815;

        $absoluteImagePath = null;

        $image = $this->_scopeConfig->getValue(
            'sales/identity/logo',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $store
        );
        if ($image) {
            $imagePath = '/sales/store/logo/' . $image;
            if ($this->fileStorageDatabase->checkDbUsage() &&
                !$this->_mediaDirectory->isFile($imagePath)
            ) {
                $this->fileStorageDatabase->saveFileToFilesystem($imagePath);
            }
            if ($this->_mediaDirectory->isFile($imagePath)) {
                $absoluteImagePath = $this->_mediaDirectory->getAbsolutePath($imagePath);
            }
        }

        // No configured logo (or the configured file is missing) -> fall back to the
        // alpha-free logo bundled with the module so the invoice PDF always carries a logo.
        if ($absoluteImagePath === null) {
            $moduleLogo = $this->moduleReader->getModuleDir(
                \Magento\Framework\Module\Dir::MODULE_VIEW_DIR,
                'Evalent_InvoicePaymentMods'
            ) . '/base/web/images/ca_logo_pdf.png';
            if (is_file($moduleLogo)) {
                $absoluteImagePath = $moduleLogo;
            }
        }

        if ($absoluteImagePath !== null) {
                $image = \Zend_Pdf_Image::imageWithPath($absoluteImagePath);
                $top = 830;
                //top border of the page
                $widthLimit = 200;
                //half of the page width
                $heightLimit = 100;
                //assuming the image is not a "skyscraper"
                $width = $image->getPixelWidth();
                $height = $image->getPixelHeight();

                //preserving aspect ratio (proportions)
                $ratio = $width / $height;
                if ($ratio > 1 && $width > $widthLimit) {
                    $width = $widthLimit;
                    $height = $width / $ratio;
                } elseif ($ratio < 1 && $height > $heightLimit) {
                    $height = $heightLimit;
                    $width = $height * $ratio;
                } elseif ($ratio == 1 && $height > $heightLimit) {
                    $height = $heightLimit;
                    $width = $widthLimit;
                }

                $y1 = $top - $height;
                $y2 = $top;
                $x1 = 565 - $width;
                $x2 = $x1 + $width;

                //coordinates after transformation are rounded by Zend
                $page->drawImage($image, $x1, $y1, $x2, $y2);

                $this->y = $y1 - 10;
            }
    }

    public function drawLineBlocks(\Zend_Pdf_Page $page, array $draw, array $pageSettings = [])
    {
        $this->pageSettings = $pageSettings;
        foreach ($draw as $itemsProp) {
            if (!isset($itemsProp['lines']) || !is_array($itemsProp['lines'])) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('We don\'t recognize the draw line data. Please define the "lines" array.')
                );
            }
            $lines = $itemsProp['lines'];
            $height = isset($itemsProp['height']) ? $itemsProp['height'] : 10;
            if (empty($itemsProp['shift'])) {
                $shift = 0;
                foreach ($lines as &$line) {
                    $maxHeight = 0;
                    foreach ($line as &$column) {
                        $lineSpacing = !empty($column['height']) ? $column['height'] : $height;
                        if (!is_array($column['text'])) {
                            $column['text'] = [$column['text']];
                        }
                        $top = 0;
                        //
                        foreach ($column['text'] as $part) {
                            $top += $lineSpacing;
                        }

                        $maxHeight = $top > $maxHeight ? $top : $maxHeight;

                    }
                    $shift += $maxHeight;
                }
                $itemsProp['shift'] = $shift;
            }
            $pageDelimiter = $this->hasFooter ? 120 : 15;
            if ($this->y - $itemsProp['shift'] < $pageDelimiter) { //Edited this value to make space for footers
                $page = $this->newPage($pageSettings);
            }
            $this->correctLines($lines, $page, $height);
        }

        return $page;
    }

    /**
     * Set font as regular
     *
     * @param  \Zend_Pdf_Page $object
     * @param  int $size
     * @return \Zend_Pdf_Resource_Font
     */
    protected function _setFontRegular($object, $size = 7)
    {
        switch ($this->invoiceLanguage) {
            case "jp":
                $japaneseFont = $this->moduleReader->getModuleDir(
                    \Magento\Framework\Module\Dir::MODULE_VIEW_DIR,
                    'Evalent_InvoicePaymentMods'
                ) . "/cid0jp.ttf";
                $column['font_file'] = $japaneseFont ;
                $font = \Zend_Pdf_Font::fontWithPath($japaneseFont);
                break;
            default:
                $font = \Zend_Pdf_Font::fontWithPath(
                    $this->_rootDirectory->getAbsolutePath('lib/internal/GnuFreeFont/FreeSerif.ttf')
                );
                break;
        }

        $object->setFont($font, $size);
        return $font;
    }


    /**
     * Set font as bold
     *
     * @param  \Zend_Pdf_Page $object
     * @param  int $size
     * @return \Zend_Pdf_Resource_Font
     */
    protected function _setFontBold($object, $size = 7)
    {
        switch ($this->invoiceLanguage) {
            case "jp":
                $japaneseFont = $this->moduleReader->getModuleDir(
                        \Magento\Framework\Module\Dir::MODULE_VIEW_DIR,
                        'Evalent_InvoicePaymentMods'
                    ) . "/cid0jp.ttf";
                $column['font_file'] = $japaneseFont ;
                $font = \Zend_Pdf_Font::fontWithPath($japaneseFont);
                break;
            default:
                $font = \Zend_Pdf_Font::fontWithPath(
                    $this->_rootDirectory->getAbsolutePath('lib/internal/GnuFreeFont/FreeSerifBold.ttf')
                );
                break;
        }
        $object->setFont($font, $size);
        return $font;
    }

    /**
     * Set font as italic
     *
     * @param  \Zend_Pdf_Page $object
     * @param  int $size
     * @return \Zend_Pdf_Resource_Font
     */
    protected function _setFontItalic($object, $size = 7)
    {
        switch ($this->invoiceLanguage) {
            case "jp":
                $japaneseFont = $this->moduleReader->getModuleDir(
                        \Magento\Framework\Module\Dir::MODULE_VIEW_DIR,
                        'Evalent_InvoicePaymentMods'
                    ) . "/cid0jp.ttf";
                $column['font_file'] = $japaneseFont ;
                $font = \Zend_Pdf_Font::fontWithPath($japaneseFont);
                break;
            default:
                $font = \Zend_Pdf_Font::fontWithPath(
                    $this->_rootDirectory->getAbsolutePath('lib/internal/GnuFreeFont/FreeSerifItalic.ttf')
                );
                break;
        }
        $object->setFont($font, $size);
        return $font;
    }

    /**
     * Correct lines.
     *
     * @param array $lines
     * @param \Zend_Pdf_Page $page
     * @param int $height
     * @throws \Zend_Pdf_Exception
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function correctLines($lines, $page, $height) :void
    {
        foreach ($lines as $line) {
            $maxHeight = 0;
            foreach ($line as $column) {
                $fontSize = empty($column['font_size']) ? 10 : $column['font_size'];
                if (!empty($column['font_file'])) {
                    $font = \Zend_Pdf_Font::fontWithPath($column['font_file']);
                    $page->setFont($font, $fontSize);
                } else {
                    $fontStyle = empty($column['font']) ? 'regular' : $column['font'];
                    switch ($fontStyle) {
                        case 'bold':
                            $font = $this->_setFontBold($page, $fontSize);
                            break;
                        case 'italic':
                            $font = $this->_setFontItalic($page, $fontSize);
                            break;
                        default:
                            $font = $this->_setFontRegular($page, $fontSize);
                            break;
                    }
                }

                if (!is_array($column['text'])) {
                    $column['text'] = [$column['text']];
                }
                $top = $this->correctText($column, $height, $font, $page);

                $maxHeight = $top > $maxHeight ? $top : $maxHeight;
            }
            $this->y -= $maxHeight;
        }
    }

    /**
     * Correct text.
     *
     * @param array $column
     * @param int $height
     * @param \Zend_Pdf_Resource_Font $font
     * @param \Zend_Pdf_Page $page
     * @throws \Zend_Pdf_Exception
     * @return int
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function correctText($column, $height, $font, $page) :int
    {
        $top = 0;
        $lineSpacing = !empty($column['height']) ? $column['height'] : $height;
        $fontSize = empty($column['font_size']) ? 10 : $column['font_size'];
        foreach ($column['text'] as $part) {
            if ($this->y - $lineSpacing < 15) {
                $page = $this->newPage($this->pageSettings);
            }

            $feed = $column['feed'];
            $textAlign = empty($column['align']) ? 'left' : $column['align'];
            $width = empty($column['width']) ? 0 : $column['width'];
            switch ($textAlign) {
                case 'right':
                    if ($width) {
                        $feed = $this->getAlignRight($part, $feed, $width, $font, $fontSize);
                    } else {
                        $feed = $feed - $this->widthForStringUsingFontSize($part, $font, $fontSize);
                    }
                    break;
                case 'center':
                    if ($width) {
                        $feed = $this->getAlignCenter($part, $feed, $width, $font, $fontSize);
                    }
                    break;
                default:
                    break;
            }
            $page->drawText($part, $feed, $this->y - $top, 'UTF-8');
            $top += $lineSpacing;
        }
        return $top;
    }

    protected function _drawItem(
        \Magento\Framework\DataObject $item,
        \Zend_Pdf_Page $page,
        \Magento\Sales\Model\Order $order
    ) {
        $type = $item->getOrderItem()->getProductType();
        $renderer = $this->_getRenderer($type);
        $renderer->setOrder($order);
        $renderer->setItem($item);
        $renderer->setPdf($this);
        $renderer->setPage($page);
        $renderer->setRenderedModel($this);
        $renderer->draw();

        return $renderer->getPage();
    }

    public function draw()
    {
        $order = $this->getOrder();
        $item = $this->getItem();
        $pdf = $this->getPdf();
        $page = $this->getPage();
        $lines = [];

        // draw Product name
        $lines[0] = [
            [
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                'text' => $this->string->split(html_entity_decode($item->getName()), 35, true, true),
                'feed' => 35,
            ],
        ];

        // draw SKU
        $lines[0][] = [
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            'text' => $this->string->split(html_entity_decode($this->getSku($item)), 17),
            'feed' => 290,
            'align' => 'right',
        ];

        // draw QTY
        $lines[0][] = ['text' => $item->getQty() * 1, 'feed' => 435, 'align' => 'right'];

        // draw item Prices
        $i = 0;
        $prices = $this->getItemPricesForDisplay();
        $feedPrice = 395;
        $feedSubtotal = $feedPrice + 170;
        foreach ($prices as $priceData) {
            if (isset($priceData['label'])) {
                // draw Price label
                $lines[$i][] = ['text' => $priceData['label'], 'feed' => $feedPrice, 'align' => 'right'];
                // draw Subtotal label
                $lines[$i][] = ['text' => $priceData['label'], 'feed' => $feedSubtotal, 'align' => 'right'];
                $i++;
            }
            // draw Price
            $lines[$i][] = [
                'text' => $priceData['price'],
                'feed' => $feedPrice,
                'font' => 'bold',
                'align' => 'right',
            ];
            // draw Subtotal
            $lines[$i][] = [
                'text' => $priceData['subtotal'],
                'feed' => $feedSubtotal,
                'font' => 'bold',
                'align' => 'right',
            ];
            $i++;
        }


        // draw Tax
        $lines[0][] = [
            'text'  => $order->formatPriceTxt($item->getTaxAmount()),
            'feed'  => 495,
            'font'  => 'bold',
            'align' => 'right',
        ];



        // custom options
        $options = $this->getItemOptions();
        if ($options) {
            foreach ($options as $option) {
                // draw options label
                $lines[][] = [
                    'text' => $this->string->split($this->filterManager->stripTags($option['label']), 40, true, true),
                    'font' => 'italic',
                    'feed' => 35,
                ];

                // Checking whether option value is not null
                if ($option['value'] !== null) {
                    if (isset($option['print_value'])) {
                        $printValue = $option['print_value'];
                    } else {
                        $printValue = $this->filterManager->stripTags($option['value']);
                    }
                    $values = explode(', ', $printValue);
                    foreach ($values as $value) {
                        $lines[][] = ['text' => $this->string->split($value, 30, true, true), 'feed' => 40];
                    }
                }
            }
        }

        $lineBlock = ['lines' => $lines, 'height' => 20];

        $page = $pdf->drawLineBlocks($page, [$lineBlock], ['table_header' => true]);
        $this->setPage($page);
    }
}
