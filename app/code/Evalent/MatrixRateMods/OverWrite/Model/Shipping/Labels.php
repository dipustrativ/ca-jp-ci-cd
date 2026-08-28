<?php

declare(strict_types=1);

namespace Evalent\MatrixRateMods\OverWrite\Model\Shipping;

use Evalent\MatrixRateMods\Helper\Data;
use Magento\Framework\DataObject;
use Magento\Sales\Model\Order\Shipment;
use Magento\Shipping\Model\Shipment\Request;
use Magento\Store\Model\ScopeInterface;
use Magento\User\Model\User;

class Labels extends \Magento\Shipping\Model\Shipping\Labels
{

    /**
     * @var \Evalent\MatrixRateMods\Helper\Data
     */
    private $dataHelper;

    public function __construct(
        Data $dataHelper,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Shipping\Model\Config $shippingConfig,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Shipping\Model\CarrierFactory $carrierFactory,
        \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory,
        \Magento\Shipping\Model\Shipment\RequestFactory $shipmentRequestFactory,
        \Magento\Directory\Model\RegionFactory $regionFactory,
        \Magento\Framework\Math\Division $mathDivision,
        \Magento\CatalogInventory\Api\StockRegistryInterface $stockRegistry,
        \Magento\Backend\Model\Auth\Session $authSession,
        Request $request
    ) {
        parent::__construct($scopeConfig, $shippingConfig, $storeManager,
            $carrierFactory, $rateResultFactory, $shipmentRequestFactory,
            $regionFactory, $mathDivision, $stockRegistry, $authSession,
            $request);
        $this->dataHelper = $dataHelper;
    }

    /**
     * Set shipper details into request
     * @param \Magento\Shipping\Model\Shipment\Request $request
     * @param \Magento\User\Model\User $storeAdmin
     * @param \Magento\Framework\DataObject $store
     * @param $shipmentStoreId
     * @param $regionCode
     * @param $originStreet
     * @return void
     */
    protected function setShipperDetails(
        Request $request,
        User $storeAdmin,
        DataObject $store,
        $shipmentStoreId,
        $regionCode,
        $originStreet
    ) {
        $originStreet2 = $this->_scopeConfig->getValue(
            Shipment::XML_PATH_STORE_ADDRESS2,
            ScopeInterface::SCOPE_STORE,
            $shipmentStoreId
        );

        $storeId = $request->getOrderShipment()->getStoreId();

        $senderName = $this->dataHelper->getShippingLabelSenderName(ScopeInterface::SCOPE_STORE, $storeId);
        $senderCompany = $this->dataHelper->getShippingLabelSenderCompany(ScopeInterface::SCOPE_STORE, $storeId);

        $request->setShipperContactPersonName($senderName ?? $storeAdmin->getName());
        $request->setShipperContactPersonFirstName($senderName ?? $storeAdmin->getFirstName());
        $request->setShipperContactPersonLastName($senderName ? "" : $storeAdmin->getLastName());
        $request->setShipperContactCompanyName($senderCompany ?? $store->getName());
        $request->setShipperContactPhoneNumber($store->getPhone());
        $request->setShipperEmail($storeAdmin->getEmail());
        $request->setShipperAddressStreet(trim($originStreet . ' ' . $originStreet2));
        $request->setShipperAddressStreet1($originStreet);
        $request->setShipperAddressStreet2($originStreet2);
        $request->setShipperAddressCity(
            $this->_scopeConfig->getValue(
                Shipment::XML_PATH_STORE_CITY,
                ScopeInterface::SCOPE_STORE,
                $shipmentStoreId
            )
        );
        $request->setShipperAddressStateOrProvinceCode($regionCode);
        $request->setShipperAddressPostalCode(
            $this->_scopeConfig->getValue(
                Shipment::XML_PATH_STORE_ZIP,
                ScopeInterface::SCOPE_STORE,
                $shipmentStoreId
            )
        );
        $request->setShipperAddressCountryCode(
            $this->_scopeConfig->getValue(
                Shipment::XML_PATH_STORE_COUNTRY_ID,
                ScopeInterface::SCOPE_STORE,
                $shipmentStoreId
            )
        );
    }
}