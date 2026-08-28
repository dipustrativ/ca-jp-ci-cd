<?php
declare(strict_types=1);

namespace Evalent\ProductCertificate\Observer;

class BeforeSendShipmentCommentObserver extends AbstractSendShipmentObserver
{
    const XML_PATH_ATTACH_PDF = 'sales_pdf/coa/attach_to_shipment_comment';
}
