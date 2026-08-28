<?php

namespace CarpetAvenue\Orderify\Model\Order\Pdf;

/**
 * Sales Order Creditmemo PDF model
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */

use CarpetAvenue\Orderify\Model\Config\Source\PdfFooterTypes;
use Magento\Customer\Model\Vat;
use Magento\Framework\App\RequestInterface;
use Magento\Sales\Model\Order\Pdf\Config;
use Magento\Store\Model\ScopeInterface;

class Creditmemo extends \Magento\Sales\Model\Order\Pdf\Creditmemo
{

    protected $invoiceStoreId;

    protected $hasFooter;

    protected $localeResolver;

    /**
     * @var \Magento\Customer\Model\Vat
     */
    protected $vatHelper;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    protected $request;

    /**
     * @var \Magento\Framework\Module\Dir\Reader
     */
    protected $moduleReader;

    protected $ignoreTax;

    public function __construct(
        Vat $vatHelper,
        RequestInterface $request,
        \Magento\Framework\Locale\ResolverInterface $localeResolver,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\Stdlib\StringUtils $string,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Filesystem $filesystem,
        Config $pdfConfig,
        \Magento\Sales\Model\Order\Pdf\Total\Factory $pdfTotalFactory,
        \Magento\Sales\Model\Order\Pdf\ItemsFactory $pdfItemsFactory,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Magento\Framework\Translate\Inline\StateInterface $inlineTranslation,
        \Magento\Sales\Model\Order\Address\Renderer $addressRenderer,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\Module\Dir\Reader $moduleReader,
        \Magento\Store\Model\App\Emulation $appEmulation,
        array $data = []
    ) {
        parent::__construct($paymentData, $string, $scopeConfig, $filesystem,
            $pdfConfig, $pdfTotalFactory, $pdfItemsFactory, $localeDate,
            $inlineTranslation, $addressRenderer, $storeManager, $appEmulation,
            $data);

        $this->vatHelper = $vatHelper;
        $this->request = $request;
        $this->localeResolver = $localeResolver;
        $this->moduleReader = $moduleReader;
    }

    /**
     * Return PDF document
     *
     * @param  array $creditmemos
     * @return \Zend_Pdf
     */
    public function getPdf($creditmemos = [])
    {
        $this->_beforeGetPdf();
        $this->_initRenderer('creditmemo');

        $pdf = new \Zend_Pdf();
        $this->_setPdf($pdf);
        $style = new \Zend_Pdf_Style();
        $this->_setFontBold($style, 10);

        foreach ($creditmemos as $creditmemo) {
            $this->invoiceStoreId = 0;
            if ($creditmemo->getStoreId()) {
                $this->localeResolver->emulate($creditmemo->getStoreId());
                $this->_storeManager->setCurrentStore($creditmemo->getStoreId());
                $this->invoiceStoreId = $creditmemo->getStore();
            }
            if (($creditmemo->getOrder()->getCustomerTaxvat() == "" && $creditmemo->getOrder()->getTaxAmount() > 0
                    && $this->vatHelper->isCountryInEU($creditmemo->getOrder()->getShippingAddress()->getCountryId())
                    && $creditmemo->getOrder()->getShippingAddress()->getCountryId() != "DE"
                    && !$this->request->getParam('legacy')
                )
                || $creditmemo->getOrder()->getShippingAddress()->getCountryId() == "CH"
                || $creditmemo->getOrder()->getShippingAddress()->getCountryId() == "JP"
            ) {
                $this->ignoreTax = true;
            } else {
                $this->ignoreTax = false;
            }
            $page = $this->newPage();
            $order = $creditmemo->getOrder();
            /* Add image */
            $this->insertLogo($page, $creditmemo->getStore());
            /* Add address */
            $this->insertAddress($page, $creditmemo->getStore());
            /* Add head */
            $this->insertOrderCreditmemo(
                $page,
                $order,
                $creditmemo,
                $this->_scopeConfig->isSetFlag(
                    self::XML_PATH_SALES_PDF_CREDITMEMO_PUT_ORDER_ID,
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $order->getStoreId()
                )
            );
            /* Add document text and number */
            $this->insertDocumentNumber($page, __('Credit Memo') . ' # ' . $creditmemo->getIncrementId());
            /* Add table head */
            $this->_drawHeader($page);
            /* Add body */
            foreach ($creditmemo->getAllItems() as $item) {
                if ($item->getOrderItem()->getParentItem()) {
                    continue;
                }
                /* Draw item */
                $this->_drawItem($item, $page, $order);
                $page = end($pdf->pages);
            }
            /* Add totals */
            $this->insertTotals($page, $creditmemo);
        }
        $this->_afterGetPdf();
        if ($creditmemo->getStoreId()) {
            $this->localeResolver->revert();
        }
        return $pdf;
    }

    protected function _drawFooter(\Zend_Pdf_Page $page)
    {
        $this->hasFooter = false;
        $pdfStyle = $this->_scopeConfig->getValue('payment/evinvoicepayment/pdf_footer_type', ScopeInterface::SCOPE_STORE, $this->invoiceStoreId);
        $pdfStyle = 2;
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
     * Insert order AND creditmemo to pdf page.
     *
     * @param \Zend_Pdf_Page $page
     * @param \Magento\Sales\Model\Order $obj
     * @param \Magento\Sales\Model\Order\Creditmemo $creditmemo
     * @param bool $putOrderId
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function insertOrderCreditmemo(&$page, $obj, $creditmemo, $putOrderId = true)
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
        $page->drawRectangle(25, $top, 570, $top - 70);
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(1));
        $this->setDocHeaderCoordinates([25, $top, 570, $top - 70]);
        $this->_setFontRegular($page, 10);

        //------------------------------------------------------------
        // Add creditmemo "created date"
        //------------------------------------------------------------
        $top -=30;
        $page->drawText(
            __('Date') . ': ' .
            $this->_localeDate->formatDate(
                $this->_localeDate->scopeDate(
                    $order->getStore(),
                    $creditmemo->getCreatedAt(),
                    true
                ),
                \IntlDateFormatter::MEDIUM,
                false
            ),
            35,
            $top,
            'UTF-8'
        );

        $top +=15;
        if ($putOrderId) {
            $page->drawText(__('Order # ') . $order->getRealOrderId(), 35, $top -= 30, 'UTF-8');
            $top +=15;
        }

        $top -=30;
        $page->drawText(
            __('Order Date') . ": " .
            $this->_localeDate->formatDate(
                $this->_localeDate->scopeDate(
                    $order->getStore(),
                    $order->getCreatedAt(),
                    true
                ),
                \IntlDateFormatter::MEDIUM,
                false
            ),
            35,
            $top,
            'UTF-8'
        );

        $top -= 10;
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0.93, 0.92, 0.92));
        $page->setLineColor(new \Zend_Pdf_Color_GrayScale(0.5));
        $page->setLineWidth(0.5);
        $page->drawRectangle(25, $top, 275, $top - 25);
        $page->drawRectangle(275, $top, 570, $top - 25);

        /* Calculate blocks info */

        /* Billing Address */
        $billingAddress = $this->_formatAddress($this->addressRenderer->format($order->getBillingAddress(), 'pdf'));

        /* Payment */
        $paymentInfo = $this->_paymentData->getInfoBlock($order->getPayment())->setIsSecureMode(true)->toPdf();
        $paymentInfo = htmlspecialchars_decode($paymentInfo, ENT_QUOTES);
        // TODO: Get additional payment info
        $paymentAdditionalInfo = $order->getPayment()->getData('additional_information');
        $payment = explode('{{pdf_row_separator}}', $paymentInfo);
        foreach ($payment as $key => $value) {
            if (strip_tags(trim($value)) == '') {
                unset($payment[$key]);
            }
        }
        //reset($payment);

        /* Shipping Address and Method */
        if (!$order->getIsVirtual()) {
            /* Shipping Address */
            $shippingAddress = $this->_formatAddress(
                $this->addressRenderer->format($order->getShippingAddress(), 'pdf')
            );
            $shippingMethod = $order->getShippingDescription();
        }

        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
        $this->_setFontBold($page, 12);
        $page->drawText(__('Sold to:'), 35, $top - 15, 'UTF-8');

        if (!$order->getIsVirtual()) {
            $page->drawText(__('Ship to:'), 285, $top - 15, 'UTF-8');
        } else {
            $page->drawText(__('Payment Method:'), 285, $top - 15, 'UTF-8');
        }

        $addressesHeight = $this->_calcAddressHeight($billingAddress);
        if (isset($shippingAddress)) {
            $addressesHeight = max($addressesHeight, $this->_calcAddressHeight($shippingAddress));
        }

        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(1));
        $page->drawRectangle(25, $top - 25, 570, $top - 33 - $addressesHeight);
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
        $this->_setFontRegular($page, 10);
        $this->y = $top - 40;
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
            $page->drawRectangle(25, $this->y, 275, $this->y - 25);
            $page->drawRectangle(275, $this->y, 570, $this->y - 25);

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


        // Show only additional payment info "brand code" - if set
        if(is_array($paymentAdditionalInfo) && isset($paymentAdditionalInfo['brand_code'])) {

            $page->drawText(strip_tags(trim($paymentAdditionalInfo['brand_code'])), $paymentLeft, $yPayments, 'UTF-8');
            $yPayments -= 15;
        } else {

            // Else show the standard payment info lines
            foreach ($payment as $value) {
                if (trim($value) != '') {
                    //Printing "Payment Method" lines
                    $value = preg_replace('/<br[^>]*>/i', "\n", $value);
                    foreach ($this->string->split($value, 45, true, true) as $_value) {
                        $page->drawText(strip_tags(trim($_value)), $paymentLeft, $yPayments, 'UTF-8');
                        $yPayments -= 15;
                    }
                }
            }
        }

        reset($payment);

        if ($order->getIsVirtual()) {
            // replacement of Shipments-Payments rectangle block
            $yPayments = min($addressesEndY, $yPayments);
            $page->drawLine(25, $top - 25, 25, $yPayments);
            $page->drawLine(570, $top - 25, 570, $yPayments);
            $page->drawLine(25, $yPayments, 570, $yPayments);

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
            $totalShippingChargesText = "("
                . __('Total Shipping Charges')
                . " "
                . $order->formatPriceTxt($order->getShippingAmount())
                . ")";

            $page->drawText($totalShippingChargesText, 285, $yShipments - $topMargin, 'UTF-8');
            $yShipments -= $topMargin + 10;

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
            $page->drawLine(25, $methodStartY, 25, $currentY);
            //left
            $page->drawLine(25, $currentY, 570, $currentY);
            //bottom
            $page->drawLine(570, $currentY, 570, $methodStartY);
            //right

            $this->y = $currentY;
            $this->y -= 15;
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
        $page = parent::newPage($settings);
        if (!empty($settings['table_header'])) {
            $this->_drawHeader($page);
        }
        $this->_drawFooter($page);
        return $page;
    }

    /**
     * Draw table header for product items
     *
     * @param  \Zend_Pdf_Page $page
     * @return void
     */
    protected function _drawHeader(\Zend_Pdf_Page $page)
    {
        $this->_setFontRegular($page, 10);
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0.93, 0.92, 0.92));
        $page->setLineColor(new \Zend_Pdf_Color_GrayScale(0.5));
        $page->setLineWidth(0.5);
        $page->drawRectangle(25, $this->y, 570, $this->y - 30);
        $this->y -= 10;
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0, 0, 0));

        //columns headers
        $lines[0][] = ['text' => __('Products'), 'feed' => 35];

        $lines[0][] = [
            'text' => __('SKU'),
            'feed' => 265,
            'align' => 'right',
        ];

        $lines[0][] = [
            'text' => $this->string->split(__('Price'), 12, true, true),
            'feed' => 330,
            'align' => 'right',
        ];

        $lines[0][] = [
            'text' => $this->string->split(__('Discount'), 12, true, true),
            'feed' => 380,
            'align' => 'right',
        ];

        $lines[0][] = [
            'text' => $this->string->split(__('Qty'), 12, true, true),
            'feed' => 440,
            'align' => 'right',
        ];

        if (!$this->ignoreTax) {
            $lines[0][] = [
                'text'  => $this->string->split(__('Tax'), 12, true, true),
                'feed'  => 490,
                'align' => 'right',
            ];
        }

        $lines[0][] = [
            'text' => __('Subtotal'),
            'feed' => 565,
            'align' => 'right',
        ];

        $lineBlock = ['lines' => $lines, 'height' => 10];

        $this->drawLineBlocks($page, [$lineBlock], ['table_header' => true]);
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
        $this->y -= 20;
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
        switch ($this->_storeManager->getStore()->getCode()) {
            case "jp":
                $japaneseFont = $this->moduleReader->getModuleDir(
                        \Magento\Framework\Module\Dir::MODULE_VIEW_DIR,
                        'CarpetAvenue_Orderify'
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
        switch ($this->_storeManager->getStore()->getCode()) {
            case "jp":
                $japaneseFont = $this->moduleReader->getModuleDir(
                        \Magento\Framework\Module\Dir::MODULE_VIEW_DIR,
                        'CarpetAvenue_Orderify'
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
        switch ($this->_storeManager->getStore()->getCode()) {
            case "jp":
                $japaneseFont = $this->moduleReader->getModuleDir(
                        \Magento\Framework\Module\Dir::MODULE_VIEW_DIR,
                        'CarpetAvenue_Orderify'
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
}
