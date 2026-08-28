<?php

namespace Evalent\MatrixRateMods\Plugin\Ups\Model;

use Evalent\MatrixRateMods\Helper\Data;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Async\CallbackDeferred;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\AsyncClient\HttpResponseDeferredInterface;
use Magento\Framework\HTTP\AsyncClient\Request;
use Magento\Framework\HTTP\AsyncClientInterface;
use Magento\Framework\HTTP\ClientFactory;
use Magento\Framework\Xml\Security;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\Result\ProxyDeferredFactory;
use Magento\Shipping\Model\Shipment\Request as Shipment;
use Magento\Shipping\Model\Simplexml\Element;
use Magento\Ups\Helper\Config;

class Carrier extends \Magento\Ups\Model\Carrier
{

    /**
     * @var \Magento\Framework\HTTP\AsyncClientInterface|null
     */
    private $asyncHttpClient;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        \Psr\Log\LoggerInterface $logger,
        Security $xmlSecurity,
        \Magento\Shipping\Model\Simplexml\ElementFactory $xmlElFactory,
        \Magento\Shipping\Model\Rate\ResultFactory $rateFactory,
        \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
        \Magento\Shipping\Model\Tracking\ResultFactory $trackFactory,
        \Magento\Shipping\Model\Tracking\Result\ErrorFactory $trackErrorFactory,
        \Magento\Shipping\Model\Tracking\Result\StatusFactory $trackStatusFactory,
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Magento\Directory\Model\CountryFactory $countryFactory,
        \Magento\Directory\Model\CurrencyFactory $currencyFactory,
        \Magento\Directory\Helper\Data $directoryData,
        \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        \Magento\Framework\Locale\FormatInterface $localeFormat,
        Config $configHelper,
        \Magento\Ups\Model\UpsAuth $upsAuth,
        ClientFactory $httpClientFactory,
        array $data = [],
        ?AsyncClientInterface $asyncHttpClient = null,
        ?ProxyDeferredFactory $proxyDeferredFactory = null
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger,
            $xmlSecurity, $xmlElFactory, $rateFactory, $rateMethodFactory,
            $trackFactory, $trackErrorFactory, $trackStatusFactory,
            $regionFactory, $countryFactory, $currencyFactory, $directoryData,
            $stockRegistry, $localeFormat, $configHelper, $upsAuth, $httpClientFactory,
            $data, $asyncHttpClient, $proxyDeferredFactory);
        $this->asyncHttpClient = $asyncHttpClient ?? ObjectManager::getInstance()->get(AsyncClientInterface::class);
    }

    public function collectRates(RateRequest $request)
    {
        return false;
    }


    /** This function is just overwritten to get a more usefull error message than just "Failed to send items" */
    /**
     * @inheritDoc
     */
    public function requestToShipment($request)
    {
        $packages = $request->getPackages();
        if (!is_array($packages) || !$packages) {
            throw new LocalizedException(__('No packages for request'));
        }
        if ($request->getStoreId() != null) {
            $this->setStore($request->getStoreId());
        }
        /** @var Shipment[] $packageRequests */
        $packageRequests = [];
        //Preparing packages info.
        foreach ($packages as $packageId => $package) {
            $request->setPackageId($packageId);
            $request->setPackagingType($package['params']['container']);
            $request->setPackageWeight($package['params']['weight']);
            $request->setPackageParams(new DataObject($package['params']));
            $request->setPackageItems($package['items']);
            $packageRequests[] = clone $request;
        }

        // phpcs:disable
        try {
            $quoteIds = $this->requestQuotes($packageRequests);
            $labels = $this->requestShipments($quoteIds);
        } catch (LocalizedException $exception) {
            return new DataObject(['errors' => [$exception->getMessage()]]);
        } catch (\RuntimeException $exception) {
            return new DataObject(['errors' => __('Failed to send items') . " " . $exception->getMessage()]);
        }
        // phpcs:enable

        return new DataObject(['info' => $labels]);
    }

    /**
     * Request quotes for given packages.
     *
     * @param DataObject[] $packages
     * @return string[] Quote IDs.
     * @throws LocalizedException
     * @throws \RuntimeException
     */
    private function requestQuotes(array $packages): array
    {
        /** @var HttpResponseDeferredInterface[] $quotesRequests */
        $quotesRequests = [];
        //Getting quotes
        foreach ($packages as $package) {
            $this->_prepareShipmentRequest($package);
            $rawXmlRequest = $this->_formShipmentRequest($package);
            $this->setXMLAccessRequest();
            $xmlRequest = $this->_xmlAccessRequest . $rawXmlRequest;
            $quotesRequests[] = $this->asyncHttpClient->request(
                new Request(
                    $this->getShipConfirmUrl(),
                    Request::METHOD_POST,
                    ['Content-Type' => 'application/xml'],
                    $xmlRequest
                )
            );
        }
        $ids = [];
        //Processing quote responses
        foreach ($quotesRequests as $quotesRequest) {
            $httpResponse = $quotesRequest->get();
            if ($httpResponse->getStatusCode() >= 400) {
                throw new LocalizedException(__('Failed to get the quote'));
            }
            try {
                /** @var Element $response */
                $response = $this->_xmlElFactory->create(['data' => $httpResponse->getBody()]);
            } catch (\Throwable $e) {
                throw new \RuntimeException($e->getMessage());
            }
            if (isset($response->Response->Error)
                && in_array($response->Response->Error->ErrorSeverity, ['Hard', 'Transient'])
            ) {
                throw new \RuntimeException((string)$response->Response->Error->ErrorDescription);
            }

            $ids[] = $response->ShipmentDigest;
        }

        return $ids;
    }

    /**
     * Request UPS to ship items based on quotes.
     *
     * @param string[] $quoteIds
     * @return DataObject[]
     * @throws LocalizedException
     * @throws \RuntimeException
     */
    private function requestShipments(array $quoteIds): array
    {
        /** @var HttpResponseDeferredInterface[] $shippingRequests */
        $shippingRequests = [];
        foreach ($quoteIds as $quoteId) {
            /** @var Element $xmlRequest */
            $xmlRequest = $this->_xmlElFactory->create(
                ['data' => '<?xml version = "1.0" ?><ShipmentAcceptRequest/>']
            );
            $request = $xmlRequest->addChild('Request');
            $request->addChild('RequestAction', 'ShipAccept');
            $xmlRequest->addChild('ShipmentDigest', $quoteId);

            $shippingRequests[] = $this->asyncHttpClient->request(
                new Request(
                    $this->getShipAcceptUrl(),
                    Request::METHOD_POST,
                    ['Content-Type' => 'application/xml'],
                    $this->_xmlAccessRequest . $xmlRequest->asXml()
                )
            );
        }
        //Processing shipment requests
        /** @var DataObject[] $results */
        $results = [];
        foreach ($shippingRequests as $shippingRequest) {
            $result = new DataObject();
            $httpResponse = $shippingRequest->get();
            if ($httpResponse->getStatusCode() >= 400) {
                throw new LocalizedException(__('Failed to send the package'));
            }
            try {
                /** @var Element $response */
                $response = $this->_xmlElFactory->create(['data' => $httpResponse->getBody()]);
            } catch (\Throwable $e) {
                throw new \RuntimeException($e->getMessage());
            }
            if (isset($response->Error)) {
                throw new \RuntimeException((string)$response->Error->ErrorDescription);
            } else {
                $shippingLabelContent = (string)$response->ShipmentResults->PackageResults->LabelImage->GraphicImage;
                $trackingNumber = (string)$response->ShipmentResults->PackageResults->TrackingNumber;
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                $result->setLabelContent(base64_decode($shippingLabelContent));
                $result->setTrackingNumber($trackingNumber);
            }
            $results[] = $result;
        }

        return $results;
    }


    /**
     * Form XML for shipment request
     *
     * @param DataObject $request
     * @return string
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function _formShipmentRequest(DataObject $request)
    {
        $packageParams = $request->getPackageParams();
        $height = $packageParams->getHeight();
        $width = $packageParams->getWidth();
        $length = $packageParams->getLength();
        $weightUnits = $packageParams->getWeightUnits() == \Magento\Framework\Measure\Weight::POUND ? 'LBS' : 'KGS';
        $dimensionsUnits = $packageParams->getDimensionUnits() == \Magento\Framework\Measure\Length::INCH ? 'IN' : 'CM';

        $itemsShipment = $request->getPackageItems();
        foreach ($itemsShipment as $itemShipment) {
            $item = new DataObject();
            $item->setData($itemShipment);
        }

        $xmlRequest = $this->_xmlElFactory->create(
            ['data' => '<?xml version = "1.0" ?><ShipmentConfirmRequest xml:lang="en-US"/>']
        );
        $requestPart = $xmlRequest->addChild('Request');
        $requestPart->addChild('RequestAction', 'ShipConfirm');
        $requestPart->addChild('RequestOption', 'nonvalidate');

        $shipmentPart = $xmlRequest->addChild('Shipment');
        if ($request->getIsReturn()) {
            $returnPart = $shipmentPart->addChild('ReturnService');
            // UPS Print Return Label
            $returnPart->addChild('Code', '9');
        }
        // The Description is only for international shipment so it won't be visible on German orders. The order id is added as reference number instead
//        $shipmentPart->addChild('Description', $request->getOrderShipment()->getOrder()->getIncrementId());
        //empirical

        $shipperPart = $shipmentPart->addChild('Shipper');
        if ($request->getIsReturn()) {
            $shipperPart->addChild('Name', $request->getRecipientContactCompanyName());
            $shipperPart->addChild('AttentionName', $request->getRecipientContactPersonName());
            $shipperPart->addChild('ShipperNumber', $this->getConfigData('shipper_number'));
            $shipperPart->addChild('PhoneNumber', $request->getRecipientContactPhoneNumber());

            $addressPart = $shipperPart->addChild('Address');
            $addressPart->addChild('AddressLine1', $request->getRecipientAddressStreet());
            $addressPart->addChild('AddressLine2', $request->getRecipientAddressStreet2());
            $addressPart->addChild('City', $request->getRecipientAddressCity());
            $addressPart->addChild('CountryCode', $request->getRecipientAddressCountryCode());
            $addressPart->addChild('PostalCode', $request->getRecipientAddressPostalCode());
            // We have some issues where the state is denied by UPS, for now we ignore the state until we need it. Tobias: 20200929
//            if ($request->getRecipientAddressStateOrProvinceCode()) {
//                $addressPart->addChild('StateProvinceCode', $request->getRecipientAddressStateOrProvinceCode());
//            }
        } else {
            $shipperPart->addChild('Name', $request->getShipperContactCompanyName());
            $shipperPart->addChild('AttentionName', $request->getShipperContactPersonName());
            $shipperPart->addChild('ShipperNumber', $this->getConfigData('shipper_number'));
            $shipperPart->addChild('PhoneNumber', $request->getShipperContactPhoneNumber());

            $addressPart = $shipperPart->addChild('Address');
            $addressPart->addChild('AddressLine1', $request->getShipperAddressStreet1());
            $addressPart->addChild('AddressLine2', $request->getShipperAddressStreet2());
            $addressPart->addChild('City', $request->getShipperAddressCity());
            $addressPart->addChild('CountryCode', $request->getShipperAddressCountryCode());
            $addressPart->addChild('PostalCode', $request->getShipperAddressPostalCode());
            if ($request->getShipperAddressStateOrProvinceCode()) {
//                $addressPart->addChild('StateProvinceCode', $request->getShipperAddressStateOrProvinceCode());
            }
        }

        $shipToPart = $shipmentPart->addChild('ShipTo');
        $shipToPart->addChild('AttentionName', $request->getRecipientContactPersonName());
        $shipToPart->addChild(
            'CompanyName',
            $request->getRecipientContactCompanyName() ? $request->getRecipientContactCompanyName() : $request->getRecipientContactPersonName()
        );
        $shipToPart->addChild('PhoneNumber', $request->getRecipientContactPhoneNumber());

        $addressPart = $shipToPart->addChild('Address');
        $addressPart->addChild('AddressLine1', $request->getRecipientAddressStreet1());
        $addressPart->addChild('AddressLine2', $request->getRecipientAddressStreet2());
        $addressPart->addChild('City', $request->getRecipientAddressCity());
        $addressPart->addChild('CountryCode', $request->getRecipientAddressCountryCode());
        $addressPart->addChild('PostalCode', $request->getRecipientAddressPostalCode());
//        if ($request->getRecipientAddressStateOrProvinceCode()) {
//            $addressPart->addChild('StateProvinceCode', $request->getRecipientAddressRegionCode());
//        }
        if ($this->getConfigData('dest_type') == 'RES') {
            $addressPart->addChild('ResidentialAddress');
        }

        if ($request->getIsReturn()) {
            $shipFromPart = $shipmentPart->addChild('ShipFrom');
            $shipFromPart->addChild('AttentionName', $request->getShipperContactPersonName());
            $shipFromPart->addChild(
                'CompanyName',
                $request->getShipperContactCompanyName() ? $request
                    ->getShipperContactCompanyName() : $request
                    ->getShipperContactPersonName()
            );
            $shipFromAddress = $shipFromPart->addChild('Address');
            $shipFromAddress->addChild('AddressLine1', $request->getShipperAddressStreet1());
            $shipFromAddress->addChild('AddressLine2', $request->getShipperAddressStreet2());
            $shipFromAddress->addChild('City', $request->getShipperAddressCity());
            $shipFromAddress->addChild('CountryCode', $request->getShipperAddressCountryCode());
            $shipFromAddress->addChild('PostalCode', $request->getShipperAddressPostalCode());
//            if ($request->getShipperAddressStateOrProvinceCode()) {
//                $shipFromAddress->addChild('StateProvinceCode', $request->getShipperAddressStateOrProvinceCode());
//            }

            $addressPart = $shipToPart->addChild('Address');
            $addressPart->addChild('AddressLine1', $request->getShipperAddressStreet1());
            $addressPart->addChild('AddressLine2', $request->getShipperAddressStreet2());
            $addressPart->addChild('City', $request->getShipperAddressCity());
            $addressPart->addChild('CountryCode', $request->getShipperAddressCountryCode());
            $addressPart->addChild('PostalCode', $request->getShipperAddressPostalCode());
//            if ($request->getShipperAddressStateOrProvinceCode()) {
//                $addressPart->addChild('StateProvinceCode', $request->getShipperAddressStateOrProvinceCode());
//            }
            if ($this->getConfigData('dest_type') == 'RES') {
                $addressPart->addChild('ResidentialAddress');
            }
        }

        $servicePart = $shipmentPart->addChild('Service');
        $servicePart->addChild('Code', $request->getShippingMethod());
        $packagePart = $shipmentPart->addChild('Package');
//        $packagePart->addChild('Description', $request->getOrderShipment()->getOrder()->getIncrementId());
        //empirical
        $packagePart->addChild('PackagingType')->addChild('Code', $request->getPackagingType());
        $packageWeight = $packagePart->addChild('PackageWeight');
        $packageWeight->addChild('Weight', $request->getPackageWeight());
        $packageWeight->addChild('UnitOfMeasurement')->addChild('Code', $weightUnits);

        // set dimensions
        if ($length || $width || $height) {
            $packageDimensions = $packagePart->addChild('Dimensions');
            $packageDimensions->addChild('UnitOfMeasurement')->addChild('Code', $dimensionsUnits);
            $packageDimensions->addChild('Length', $length);
            $packageDimensions->addChild('Width', $width);
            $packageDimensions->addChild('Height', $height);
        }

        // ups support reference number only for domestic service
//        if ($this->_isUSCountry($request->getRecipientAddressCountryCode())
//            && $this->_isUSCountry($request->getShipperAddressCountryCode())
//        ) {
            if ($request->getReferenceData()) {
                $referenceData = $request->getReferenceData() . $request->getPackageId();
            } else {
                $referenceData = 'Order #' . $request->getOrderShipment()->getOrder()->getIncrementId();
            }
            $referencePart = $packagePart->addChild('ReferenceNumber');
            $referencePart->addChild('Value', $referenceData);
//        }

        $deliveryConfirmation = $packageParams->getDeliveryConfirmation();
        if ($deliveryConfirmation) {
            /** @var $serviceOptionsNode Element */
            $serviceOptionsNode = null;
            switch ($this->_getDeliveryConfirmationLevel($request->getRecipientAddressCountryCode())) {
                case self::DELIVERY_CONFIRMATION_PACKAGE:
                    $serviceOptionsNode = $packagePart->addChild('PackageServiceOptions');
                    break;
                case self::DELIVERY_CONFIRMATION_SHIPMENT:
                    $serviceOptionsNode = $shipmentPart->addChild('ShipmentServiceOptions');
                    break;
                default:
                    break;
            }
            if ($serviceOptionsNode !== null) {
                $serviceOptionsNode->addChild(
                    'DeliveryConfirmation'
                )->addChild(
                    'DCISType',
                    $packageParams->getDeliveryConfirmation()
                );
            }
        }

        $shipmentPart->addChild('PaymentInformation')
            ->addChild('Prepaid')
            ->addChild('BillShipper')
            ->addChild('AccountNumber', $this->getConfigData('shipper_number'));

        if ($request->getPackagingType() != $this->configHelper->getCode('container', 'ULE')
            && $request->getShipperAddressCountryCode() == self::USA_COUNTRY_ID
            && ($request->getRecipientAddressCountryCode() == 'CA'
                || $request->getRecipientAddressCountryCode() == 'PR')
        ) {
            $invoiceLineTotalPart = $shipmentPart->addChild('InvoiceLineTotal');
            $invoiceLineTotalPart->addChild('CurrencyCode', $request->getBaseCurrencyCode());
            $invoiceLineTotalPart->addChild('MonetaryValue', ceil($packageParams->getCustomsValue()));
        }

        $labelPart = $xmlRequest->addChild('LabelSpecification');
        $labelPart->addChild('LabelPrintMethod')->addChild('Code', 'GIF');
        $labelPart->addChild('LabelImageFormat')->addChild('Code', 'GIF');

        return $xmlRequest->asXml();
    }

}
