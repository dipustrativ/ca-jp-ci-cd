<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\Plugin\Currency;

use CarpetAvenue\Productify\Helper\CurrencyConvertConfig;

class JPCurrencyConvert
{

    public function __construct(
        protected CurrencyConvertConfig $config
    )
    {
    }

    /**
     * @param \Magento\Directory\Model\Currency $subject
     * @param $result
     * @param $price
     * @param $toCurrency
     * @return float
     */
    public function afterConvert(\Magento\Directory\Model\Currency $subject, $result, $price, $toCurrency = null): float
    {
        if (!$this->config->enabled()) {
            return floatval($result);
        }

        if ('JPY' === $this->getCurrencyCodeFromToCurrency($toCurrency)) {
            $thresholds = $this->config->getJPThresholds();
            foreach ($thresholds as $threshold) {
                if (isset($threshold['threshold_min_value'], $threshold['threshold_max_value']) && is_numeric($threshold['threshold_min_value']) && is_numeric($threshold['threshold_max_value'])) {
                    $minValue = (float)$threshold['threshold_min_value'];
                    $maxValue = (float)$threshold['threshold_max_value'];
                    $thresholdZeroes = $threshold['number_of_zeroes'] ?? 0;

                    if ($thresholdZeroes <= 0) {
                        continue;
                    }

                    if ($result >= $minValue && $result <= $maxValue) {
                        $result = ceil($result/pow(10, $thresholdZeroes)) * pow(10, $thresholdZeroes);
                        break;
                    }
                }
            }
        }

        return floatval($result);
    }

    /**
     * @param $toCurrency
     * @return string
     */
    private function getCurrencyCodeFromToCurrency($toCurrency): string
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
