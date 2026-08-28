<?php
declare(strict_types=1);

namespace Evalent\ProductCertificate\Observer;

class AbstractSendOrderObserver extends \Fooman\EmailAttachments\Observer\AbstractObserver
{
    const XML_PATH_ATTACH_PDF = 'sales_pdf/coa/attach_to_order';

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /**
         * @var \Magento\Sales\Api\Data\OrderInterface $order
         */
        $order = $observer->getOrder();
        if ($this->pdfRenderer->canRender()
            && $this->scopeConfig->getValue(
                static::XML_PATH_ATTACH_PDF,
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $order->getStoreId()
            )
        ) {
            if ($certificateString = $this->pdfRenderer->getPdfAsString([$order])){
                $this->contentAttacher->addPdf(
                    $certificateString,
                    $this->pdfRenderer->getFileName('carpet-certificates'),
                    $observer->getAttachmentContainer()
                );
            }
        }
    }
}
