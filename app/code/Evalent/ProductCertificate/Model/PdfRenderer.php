<?php
declare(strict_types=1);

namespace Evalent\ProductCertificate\Model;

class PdfRenderer implements \Fooman\EmailAttachments\Model\Api\PdfRendererInterface
{
    /** @var \Evalent\ProductCertificate\Helper\Certificate */
    protected $certificateHelper;

    protected $certificate = null;

    public function __construct(
        \Evalent\ProductCertificate\Helper\Certificate $certificateHelper

    ) {
        $this->certificateHelper = $certificateHelper;
    }

    public function getPdfAsString(array $salesObject)
    {
        $certificate = $this->certificateHelper->create($salesObject[0]);
        if(0 == $certificate['productCount']){
            return null;
        }
        return $certificate['pdf']->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    }

    public function getFileName($input = '')
    {
        return sprintf('%s.pdf', $input);
    }

    public function canRender()
    {
        return true;
    }
}
