<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Evalent\InvoicePaymentMods\Plugin;

use Magento\Sales\Model\ResourceModel\Order\Invoice\Collection;
use Magento\Sales\Model\Order\Pdf\Config;

/**
 * Sales Order Invoice PDF model
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Invoice extends \Magento\Sales\Model\Order\Pdf\Invoice
{

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
        $page->drawRectangle(25, $this->y, 570, $this->y - 15);
        $this->y -= 10;
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0, 0, 0));

        //columns headers
        $lines[0][] = ['text' => __('Products'), 'feed' => 35];

        $lines[0][] = ['text' => __('SKU'), 'feed' => 290, 'align' => 'right'];

        $lines[0][] = ['text' => __('Qty'), 'feed' => 435, 'align' => 'right'];

        $lines[0][] = ['text' => __('Price'), 'feed' => 375, 'align' => 'right'];

        $lines[0][] = ['text' => __('Tax'), 'feed' => 495, 'align' => 'right'];

        $lines[0][] = ['text' => __('Subtotal'), 'feed' => 565, 'align' => 'right'];

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

        foreach ($invoices as $invoice) {
            if ($invoice->getStoreId()) {
                $this->_localeResolver->emulate($invoice->getStoreId());
                $this->_storeManager->setCurrentStore($invoice->getStoreId());
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
            if (trim($text1) != '' || trim($text2) != '') {
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
                $page->drawRectangle(25, $this->y + 15, 275, $yFinal - 10);
                $page->drawRectangle(275, $this->y + 15, 570, $yFinal - 10);
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
            $customerEmail = $order->getCustomerEmail();
            $page->drawRectangle(25, $this->y + 5, 570, $this->y - 20, \Zend_Pdf_Page::SHAPE_DRAW_STROKE);
            $page->drawText('Customer Email: ' . $customerEmail, 30, $this->y - 10, 'UTF-8');
            $this->y -= 30;
            /// ///////////////////////////////
            /// ///////////////////////////////
            /* Add document text and number */
            $this->insertDocumentNumber($page, __('Invoice ') . $invoice->getIncrementId());//#
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
            if ($invoice->getStoreId()) {
                $this->_localeResolver->revert();
            }

        }

        $page->rotate(20, 20, 1.57);
        $invoice_virtical = $this->_scopeConfig->getValue('payment/evinvoicepayment/invoice_virtical');
        if (trim($invoice_virtical) != '') {
            $page->drawText($invoice_virtical, 300, 20);
        }
        $this->_afterGetPdf();
        return $pdf;
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
        return $page;
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
        // $page->drawRectangle(25, $top, 570, $top - 55);
        //$page->setFillColor(new \Zend_Pdf_Color_GrayScale(1));
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0, 0, 0));
        $this->setDocHeaderCoordinates([25, $top, 570, $top - 55]);
//        $this->_setFontRegular($page, 14);
        $this->_setFontBold($page, 14);

        if ($putOrderId) {
            $page->drawText(__('Order ') . $order->getRealOrderId(), 280, $top + 15, 'UTF-8');#
            $top += 15;
        }

        $top -= 30;
        $invoiceCollection = $order->getInvoiceCollection();
        $created_at = $invoiceCollection->getColumnValues('created_at');
        $page->drawText(
            __('Invoice Date: ') .
             $this->_localeDate->formatDate(
                 $this->_localeDate->scopeDate(
                     $order->getStore(),
                     $created_at[0],
                     true
                 ),
                 \IntlDateFormatter::MEDIUM,
                 false
             )
            ,
//            $this->_localeDate->formatDate(
//                $this->_localeDate->scopeDate(
//                    $order->getStore(),
//                    $order->getCreatedAt(),
//                    true
//                ),
//                \IntlDateFormatter::MEDIUM,
//                false
//            ),
            280,
            $top +15 ,
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
//        $paymentInfo = $this->_paymentData->getInfoBlock($order->getPayment())->setIsSecureMode(true)->toPdf();
//        $paymentInfo = htmlspecialchars_decode($paymentInfo, ENT_QUOTES);
//        $payment = explode('{{pdf_row_separator}}', $paymentInfo);
//        foreach ($payment as $key => $value) {
//            if (strip_tags(trim($value)) == '') {
//                unset($payment[$key]);
//            }
//        }
//        reset($payment);

        /* Shipping Address and Method */
        if (!$order->getIsVirtual()) {
            /* Shipping Address */
            $shippingAddress = $this->_formatAddress($this->addressRenderer->format($order->getShippingAddress(), 'pdf'));
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
            $page->drawText(__('Payment Method'), 35, $this->y, 'UTF-8');
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

//        foreach ($payment as $value) {
//            if (trim($value) != '') {
//                //Printing "Payment Method" lines
//                $value = preg_replace('/<br[^>]*>/i', "\n", $value);
//                foreach ($this->string->split($value, 45, true, true) as $_value) {
//                    $page->drawText(strip_tags(trim($_value)), $paymentLeft, $yPayments, 'UTF-8');
//                    $yPayments -= 15;
//                }
//            }
//        }

        $payment = $order->getPayment();
        $method = $payment->getMethodInstance();
        $methodTitle = $method->getTitle();

        $page->drawText(strip_tags(trim($methodTitle))
            , $paymentLeft, $yPayments, 'UTF-8');
        $yPayments -= 15;




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
            $totalShippingChargesText = "(" . __(
                    'Total Shipping Charges'
                ) . " " . $order->formatPriceTxt(
                    $order->getShippingAmount()
                ) . ")";

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
        $this->_setFontBold($page, 14);
        $docHeader = $this->getDocHeaderCoordinates();
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0, 0, 0));
        $page->drawText($text, 280, $docHeader[1] +30 , 'UTF-8');
    }

    /**
     * Insert totals to pdf page
     *
     * @param  \Zend_Pdf_Page $page
     * @param  \Magento\Sales\Model\AbstractModel $source
     * @return \Zend_Pdf_Page
     */
    protected function insertTotals($page, $source)
    {
        $order = $source->getOrder();
        $totals = $this->_getTotalsList();
        $lineBlock = ['lines' => [], 'height' => 15];
        foreach ($totals as $total) {
            $total->setOrder($order)->setSource($source);

            if ($total->canDisplay()) {
                $total->setFontSize(10);
                foreach ($total->getTotalsForDisplay() as $totalData) {
                    $lineBlock['lines'][] = [
                        [
                            'text' => $totalData['label'],
                            'feed' => 475,
                            'align' => 'right',
                            'font_size' => $totalData['font_size'],
                            'font' => 'bold',
                        ],
                        [
                            'text' => $totalData['amount'],
                            'feed' => 565,
                            'align' => 'right',
                            'font_size' => $totalData['font_size'],
                            'font' => 'bold'
                        ],
                    ];
                }
            }
        }

        $this->y -= 20;
        $page = $this->drawLineBlocks($page, [$lineBlock]);


        ////////////////////////////////////////////////////////////////////////////////
///
///
///
        $invoiceExtraDetails = $this->_scopeConfig->getValue('payment/evinvoicepayment/invoice_extra_details');
        if (trim($invoiceExtraDetails) != '') {
            ////////////////////////////////////////////////////////////////////////////////////////////////////
            $fontSize = 6;//$pdfContent->pages[0]->getFontSize();
            $y = $this->y;
//            $pagesCount = count($pdfContent->pages) - 1;

            $textChunk = wordwrap($invoiceExtraDetails, 180, "\n", true);
//            $TextlinesCount = 0;
//            $TextlinesCount = 14 * count(explode("\n", $textChunk));

//            if ($y - $TextlinesCount < 40) {
//                $newpage = $pdfContent->newPage(\Zend_Pdf_Page::SIZE_A4);
//                $pagesCount++;
//                $page = $newpage;
//                $y = 830;
//            }

            $page->
            setFont(\Zend_Pdf_Font::fontWithName(\Zend_Pdf_Font::FONT_HELVETICA), $fontSize);

            $width = $page->getWidth();
            $page->setLineWidth(0.5);
//                $lines = $y - 14;
            $lines = $y;

            foreach (explode("\n", $textChunk) as $textLine) {
                if ($textLine !== '') {
                    $page->
                    drawText(strip_tags(ltrim($textLine)), 30, $lines - 20, 'UTF-8');
                    $lines -= 14;
                }
            }
            $page->
            drawRectangle(25, $y - 10, $width - 25, $lines - 14, \Zend_Pdf_Page::SHAPE_DRAW_STROKE);
        }
///
///
////////////////////////////////////////////////////////////////////////////////
        return $page;
    }


}
