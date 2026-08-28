<?php
namespace Evalent\MatrixRateMods\Plugin\Model\Carrier;

class Matrixrate{

    /** @var \Evalent\MatrixRateMods\Api\ConfigInterface */
    private $config;
    
    public function __construct(
        \Evalent\MatrixRateMods\Api\ConfigInterface $config
    ){
        $this->config = $config;
    }

    public function aroundCollectRates(\WebShopApps\MatrixRate\Model\Carrier\Matrixrate $subject, callable $proceed, \Magento\Quote\Model\Quote\Address\RateRequest $request){
        if ($this->config->forceWeight()){
            if (!$request->getPackageWeight()){
                return false;
            }
        }
        return $proceed($request);
    }
}