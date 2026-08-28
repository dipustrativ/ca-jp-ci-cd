<?php
declare(strict_types=1);

namespace Evalent\ProductCertificate\Observer;

use Fooman\EmailAttachments\Model\ContentAttacher;
use Fooman\EmailAttachments\Model\TermsAndConditionsAttacher;

class AbstractSendShipmentObserver extends \Fooman\EmailAttachments\Observer\AbstractObserver
{
    const XML_PATH_ATTACH_PDF = 'sales_pdf/coa/attach_to_shipment';

    /** @var \Magento\Sales\Api\OrderRepositoryInterface */
    protected $orderRepository;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Fooman\EmailAttachments\Model\Api\PdfRendererInterface $pdfRenderer,
        TermsAndConditionsAttacher $termsAttacher,
        ContentAttacher $contentAttacher,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
    ) {
        parent::__construct($scopeConfig, $pdfRenderer, $termsAttacher, $contentAttacher);
        $this->orderRepository = $orderRepository;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /**
         * @var \Magento\Sales\Api\Data\ShipmentInterface $shipment
         */
        $shipment = $observer->getShipment();
        $order = $this->orderRepository->get($shipment->getOrderId());
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
