<?php

namespace Evalent\ProductCertificate\Api;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Certificate generation interface
 *
 * @api
 */
interface CertificateGenerationInterface
{
    /**
     * Generate order certificate and return it with application/pdf MIME type.
     *
     * @param int $orderId The order ID for which generate the certificate.
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws LocalizedException
     * @return string
     */
    public function execute($orderId);
}
