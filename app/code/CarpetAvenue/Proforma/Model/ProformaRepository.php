<?php

namespace CarpetAvenue\Proforma\Model;

use Interop\Container\Exception\NotFoundException;

class ProformaRepository
{
    public function __construct(
        protected \CarpetAvenue\Proforma\Model\ProformaFactory        $proformaFactory,
        protected \CarpetAvenue\Proforma\Model\ResourceModel\Proforma $proformaResource,
        protected \CarpetAvenue\Proforma\Model\ProformaItemFactory    $proformaItemFactory,
        protected \Magento\SalesSequence\Model\Manager                $sequenceManager,
        protected \Magento\Backend\Model\Auth\Session                 $authSession,
        protected \Magento\Sales\Model\ResourceModel\Order\Tax\Item   $taxItem,
        protected \Magento\Store\Model\App\Emulation                  $emulation,
        protected \Magento\Framework\View\LayoutInterface             $layout,
        protected \Magento\Framework\Filesystem\DirectoryList         $directoryList,
        protected \Magento\Catalog\Model\ProductRepository            $productRepository
    )
    {
    }

    public function create(\Magento\Sales\Api\Data\OrderInterface $order): \CarpetAvenue\Proforma\Model\Proforma
    {
        $proforma = $this->proformaFactory->create();
        $proforma->setOrderId($order->getEntityId());
        $this->proformaResource->load($proforma, $order->getEntityId(), 'order_id');
        if (!$proforma->getEntityId()) {
            $proforma->setOrderIncrementId($order->getIncrementId());
            $proforma->setCurrencyCode($order->getOrderCurrencyCode());
            $proforma->setCustomerId($order->getCustomerId());
            $proforma->setCustomerName($order->getBillingAddress()->getFirstname() . ' ' . $order->getBillingAddress()->getLastname());
            if (is_array($order->getBillingAddress()->getStreet())) {
                $proforma->setCustomerStreet(join(PHP_EOL, $order->getBillingAddress()->getStreet()));
            } else {
                $proforma->setCustomerStreet($order->getBillingAddress()->getStreet());
            }
            $proforma->setCustomerPostcode($order->getBillingAddress()->getPostcode());
            $proforma->setCustomerCity($order->getBillingAddress()->getCity());
            $proforma->setCustomerCountryId($order->getBillingAddress()->getCountryId());
            $proforma->setCustomerVatId($order->getBillingAddress()->getVatId());
            $adminUser = $this->authSession->getUser();
            $proforma->setContact($adminUser ? $this->authSession->getUser()->getFirstName() . ' ' . $this->authSession->getUser()->getLastName() : "IXPF API");
            $proforma->setTaxFree(3 === $order->getCustomerGroupId() ? 1 : 0);
            $proforma->setTaxPercent((($order->getGrandTotal() / ($order->getGrandTotal() - $order->getTaxAmount())) - 1) * 100);
            $proforma->setTaxAmount($order->getTaxAmount());
            $proforma->setGrandTotalExclTax($order->getGrandTotal() - $order->getTaxAmount());
            $proforma->setGrandTotalInclTax($order->getGrandTotal());
            if ($taxItems = $this->taxItem->getTaxItemsByOrderId($order->getEntityId())) {
                foreach ($taxItems as $taxItem) {
                    if ('shipping' == $taxItem['taxable_item_type']) {
                        $proforma->setShippingTaxPercent($taxItem['tax_percent']);
                    }
                }
            }
            $proforma->setShippingTaxAmount($order->getShippingTaxAmount());
            $proforma->setShippingInclTax($order->getShippingInclTax());

            foreach ($order->getItems() as $item) {
                if (null == $item->getParentItemId()) {
                    $proformaItem = $this->proformaItemFactory->create();
                    $proformaItem->setSku($item->getSku());
                    $proformaItem->setName($item->getName());
                    try {
                        $product = $this->productRepository->getById($item->getProductId());
                        $width = $product->getCustomAttribute('width');
                        $height = $product->getCustomAttribute('length');
                        if ($width !== null && $height !== null) {
                            $proformaItem->setDescription($width->getValue() . ' x ' . $height->getValue());
                        }
                    } catch (\Exception $e) {
                        //
                    }
                    $proformaItem->setQty($item->getQtyOrdered());
                    $proformaItem->setTaxPercent($item->getTaxPercent());
                    $proformaItem->setPriceTaxAmount($item->getPriceInclTax() - $item->getPrice());
                    $proformaItem->setPriceExclTax($item->getPrice());
                    $proformaItem->setPriceInclTax($item->getPriceInclTax());
                    $proformaItem->setRowTotalTaxAmount($item->getTotalTax());
                    $proformaItem->setRowTotalExclTax($item->getRowTotal() - $item->getDiscountAmount() + $item->getDiscountTaxCompensationAmount());
                    $proformaItem->setRowTotalInclTax($item->getRowTotalInclTax() - $item->getDiscountAmount());
                    $proforma->addItem($proformaItem);
                }
            }

            $sequence = $this->sequenceManager->getSequence(\CarpetAvenue\Proforma\Model\Proforma::ENTITY_TYPE, 0);
            $proforma->setIncrementId($sequence->getNextValue());
            $this->proformaResource->save($proforma);
            //fix created_at
            $proforma = $this->proformaFactory->create();
            $proforma->setOrderId($order->getEntityId());
            $this->proformaResource->load($proforma, $order->getEntityId(), 'order_id');
        }
        return $proforma;
    }

    public function generate(\Magento\Sales\Api\Data\OrderInterface $order)
    {
        $proforma = $this->proformaFactory->create();
        $proforma->setOrderId($order->getEntityId());
        $this->proformaResource->load($proforma, $order->getEntityId(), 'order_id');
        if (!$proforma->getEntityId()) {
            $proforma = $this->create($order);
        }
        $this->generatePdf($proforma, $order->getStoreId())->output($proforma->getIncrementId() . '.pdf', 'D');
    }

    public function generatePdf(\CarpetAvenue\Proforma\Model\Proforma $proforma, $storeId): \Mpdf\Mpdf
    {
        $block = $this->layout->createBlock('CarpetAvenue\Proforma\Block\Proforma');
        $block->setProforma($proforma);
        $this->emulation->startEnvironmentEmulation($storeId, \Magento\Framework\App\Area::AREA_FRONTEND, true);
        //$defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
        //$fontDirs = $defaultConfig['fontDir'];
        $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
        //$fontData = $defaultFontConfig['fontdata'];
        $mpdf = new \Mpdf\Mpdf([
            'format' => 'A5-P',
            'tempDir' => $this->directoryList->getPath('tmp'),
            'default_font' => 'dejavusans',
            'pagenumPrefix' => __('page') . ' ',
            'nbpgPrefix' => ' ' . __('of') . ' '
        ]);

        $blockFooter = $this->layout->createBlock('CarpetAvenue\Proforma\Block\Proforma');
        $blockFooter->setTemplate('CarpetAvenue_Proforma::proforma-footer.phtml');
        $footerHtml = $blockFooter->toHtml();
        $mpdf->setHTMLFooter($footerHtml);
        $html = $block->toHtml();
        $mpdf->WriteHTML($html);
        $this->emulation->stopEnvironmentEmulation();
        return $mpdf;
    }
}
