<?php
declare(strict_types=1);

namespace Evalent\ProductCertificate\Observer;

class BeforeSendOrderCommentObserver extends AbstractSendOrderObserver
{
    const XML_PATH_ATTACH_PDF = 'sales_pdf/coa/attach_to_order_comment';
}
