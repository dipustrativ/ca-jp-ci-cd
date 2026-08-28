<?php

namespace Evalent\ProductCertificate\Model\Service;

use Evalent\ProductCertificate\Helper\Certificate;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\OrderRepository;
use Mpdf\MpdfException;

/**
 * Certificate generation interface
 *
 * @api
 */
class CertificateService implements \Evalent\ProductCertificate\Api\CertificateGenerationInterface
{
    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var Certificate
     */
    private $certificate;

    public function __construct(
        OrderRepository $orderRepository,
        Certificate     $certificate
    ) {
        $this->orderRepository = $orderRepository;
        $this->certificate = $certificate;
    }

    /**
     * Generate order certificate and return it with application/pdf MIME type.
     *
     * @param int $orderId The order ID for which generate the certificate.
     * @throws InputException
     * @throws NoSuchEntityException
     * @throws LocalizedException
     * @return string
     */
    public function execute($orderId)
    {
        if (!$orderId) {
            throw new InputException(__("Order ID is needed to generate certificate."));
        }

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException $e) {
            throw new NoSuchEntityException(__("Order with ID: $orderId doesn't exist."));
        }

        try {
            $certificates = $this->certificate->create($order)['pdf'];
        } catch (\Exception $e) {
            throw new LocalizedException(__("Certificate can not be rendered: " . $e->getMessage()));
        }

        try {
            return $certificates->Output();
        } catch (MpdfException $e) {
            throw new LocalizedException(__("Error during pdf output: " . $e->getMessage()));
        }
    }
}
