<?php

namespace Evalent\MatrixRateMods\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Serialize\Serializer\Serialize;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{

    const SHIPPING_LABEL_SENDER_NAME_XML_PATH = 'shipping/shipping_label_options/sender_name';
    const SHIPPING_LABEL_SENDER_COMPANY_XML_PATH = 'shipping/shipping_label_options/sender_company';

    /**
     * @var \Magento\Framework\Serialize\Serializer\Serialize
     */
    private $serialize;

    protected \Magento\Framework\Serialize\Serializer\Json $jsonEncoder;

    private $countryMatch = null;

    public function __construct(
        Serialize $serialize,
        \Magento\Framework\Serialize\Serializer\Json $jsonEncoder,
        Context $context
    ) {
        parent::__construct($context);
        $this->serialize = $serialize;
        $this->jsonEncoder = $jsonEncoder;
    }

    /**
     * UPS Standard = 11
     * UPS Saver = 65
     * UPS 12:00 = 14
     * UPS Express = 07
     * UPS Worldwide Express Plus =  54
     * @return string[]
     */
    public function getMethods()
    {
        return [
            'ups_11' => 'UPS Standard',
            'ups_65' => 'UPS Saver',
            'ups_74' => 'UPS 12:00',
            'ups_07' => 'UPS Express',
            'ups_54' => 'UPS Worldwide Express Plus',
            'sagawa_sagawa' => 'Sagawa'
        ];
    }

    /**
     * @param string $scope
     * @param null   $scopeCode
     *
     * @return array|bool|float|int|mixed|string|null
     */
    public function getUpsPrices(
        $scope = ScopeInterface::SCOPE_STORE,
        $scopeCode = null
    ) {
        $data = $this->scopeConfig->getValue("carriers/ups/prices", $scope, $scopeCode);
        if (!$data || $data == "") {
            return [];
        }
        return $this->serialize->unserialize($data);
    }

    /**
     * @param string $scope
     * @param null   $scopeCode
     *
     * @return mixed
     */
    public function getShippingLabelSenderName($scope = ScopeInterface::SCOPE_STORE, $scopeCode = null)
    {
        return $this->scopeConfig->getValue(self::SHIPPING_LABEL_SENDER_NAME_XML_PATH, $scope, $scopeCode);
    }

    /**
     * @param string $scope
     * @param null   $scopeCode
     *
     * @return mixed
     */
    public function getShippingLabelSenderCompany($scope = ScopeInterface::SCOPE_STORE, $scopeCode = null)
    {
        return $this->scopeConfig->getValue(self::SHIPPING_LABEL_SENDER_COMPANY_XML_PATH, $scope, $scopeCode);
    }

    public function getDestTrackingUrl($carrierCode,$countryCode){
        $url = null;
        if (null === $this->countryMatch) {
            $countryMatch = $this->scopeConfig->getValue(
                'carriers/matrixrate/'.$carrierCode.'_tracking_url_dest'
            );
            try{
                $this->countryMatch = $this->jsonEncoder->unserialize($countryMatch);
            }
            catch(\Exception $ex){
                $this->countryMatch = [];
            }
            
        }
        if (is_array($this->countryMatch)) {
            foreach ($this->countryMatch as $country) {
                if ($countryCode === $country['country_code']) {
                    $url = $country['tracking_link'];
                    break;
                }
            }
        }
        return $url;
    }
}
