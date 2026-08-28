<?php

namespace Evalent\AdminActivityLog\Ui\Component\Listing\Column;

    use Evalent\OnlineCustomers\Api\Data\SingleWarmupInterface;
    use Magento\Framework\App\Config\ScopeConfigInterface;
    use Magento\Store\Model\ScopeInterface;

class Date extends \Magento\Ui\Component\Listing\Columns\Column
{

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        \Magento\Framework\View\Element\UiComponent\ContextInterface $context,
        \Magento\Framework\View\Element\UiComponentFactory $uiComponentFactory,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Prepare Data Source
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as & $item) {
                if (isset($item['modelactivity_id'])) {
                    $UTC = new \DateTimeZone('UTC');
                    $currentTZ = new \DateTimeZone(
                        $this->scopeConfig->getValue('general/locale/timezone', ScopeInterface::SCOPE_STORE)
                    );
                    $date = new \DateTime($item[$this->getData('name')], $UTC);
                    $date->setTimezone($currentTZ);
                    $item[$this->getData('name')] = [
                        $date->format('Y-m-d H:i:s')
                    ];
                }
            }
        }

        return $dataSource;
    }
}