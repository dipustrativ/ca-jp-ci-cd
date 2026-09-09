<?php
namespace CarpetAvenue\Currency\Plugin;

class Currency{
    public function __construct(
        protected \CarpetAvenue\Currency\Model\Config $config
    ){

    }
    public function afterConvert(\Magento\Directory\Model\Currency $subject, $result, $price, $toCurrency = null): float{
        if($this->config->enabled()){
            $zeroes = $this->config->numberOfZeroes();
            if ('JPY' === $this->getCurrencyCodeFromToCurrency($toCurrency) && 0 < $zeroes){
                if ($this->config->priceThreshold()<$result){
                    $result = ceil($result/pow(10,$zeroes)) * pow(10,$zeroes);
                }
            }
        }
        return $result;
    }

    private function getCurrencyCodeFromToCurrency($toCurrency)
    {
        if (is_string($toCurrency)) {
            $code = $toCurrency;
        } elseif ($toCurrency instanceof \Magento\Directory\Model\Currency) {
            $code = $toCurrency->getCurrencyCode();
        } else {
            $code = '';
        }
        return $code;
    }
}