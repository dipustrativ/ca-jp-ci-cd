<?php

namespace Evalent\AdminActivityLog\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Framework\View\Element\UiComponent\ContextInterface;

class LoginStatus extends Column
{
    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * Approve status html class array.
     *
     * @var array
     */
    protected $htmlClass = ['critical', 'notice', 'minor'];

    protected $options = [
        'Failed',
        'Success',
        'Logout',
    ];

    /**
     * Constructor
     *
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $urlBuilder
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        $this->urlBuilder = $urlBuilder;

        parent::__construct($context, $uiComponentFactory, $components, $data);
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
            $fieldName = $this->getData('name');
            foreach ($dataSource['data']['items'] as & $item) {
                if (isset($item['loginactivity_id'])) {
                    $status = $item[$this->getData('name')];
                    $item[$this->getData('name')] = sprintf(
                        '<span class="grid-severity-%s"><span>%s</span></span>',
                        $this->htmlClass[$status],
                        __($this->options[$status])
                    );
                }
            }
        }

        return $dataSource;
    }
}
