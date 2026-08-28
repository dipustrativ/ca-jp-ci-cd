<?php

declare(strict_types=1);

namespace Evalent\Banner\Ui\Component\Listing\Column;

use Magento\Backend\Model\Auth\Session;
use Magento\Framework\Authorization\PolicyInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class BannerActions extends Column
{

    const URL_PATH_EDIT = 'evalent_banner/banner/edit';
    const URL_PATH_DELETE = 'evalent_banner/banner/delete';
    const URL_PATH_DETAILS = 'evalent_banner/banner/details';
    protected $urlBuilder;

    /**
     * @var \Magento\Backend\Model\Auth\Session
     */
    private $authSession;

    /**
     * @var \Magento\Framework\Authorization\PolicyInterface
     */
    private $policy;

    /**
     * @param \Magento\Backend\Model\Auth\Session                          $authSession
     * @param \Magento\Framework\Authorization\PolicyInterface             $policy
     * @param \Magento\Framework\View\Element\UiComponent\ContextInterface $context
     * @param \Magento\Framework\View\Element\UiComponentFactory           $uiComponentFactory
     * @param \Magento\Framework\UrlInterface                              $urlBuilder
     * @param array                                                        $components
     * @param array                                                        $data
     */
    public function __construct(
        Session $authSession,
        PolicyInterface $policy,
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $uiComponentFactory, $components, $data);
        $this->authSession = $authSession;
        $this->policy = $policy;
    }

    /**
     * Prepare Data Source
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as & $item) {
                if (isset($item['entity_id'])) {
                    $user = $this->authSession->getUser();
                    $item[$this->getData('name')] = [];
                    if ($this->policy->isAllowed($user->getRole()->getId(), 'Evalent_Banner::Banner_view')) {
                        $item[$this->getData('name')]['edit'] = [
                            'href' => $this->urlBuilder->getUrl(
                                static::URL_PATH_EDIT,
                                [
                                    'entity_id' => $item['entity_id']
                                ]
                            ),
                            'label' => __('Edit')

                        ];
                    }
                    if ($this->policy->isAllowed($user->getRole()->getId(), 'Evalent_Banner::Banner_delete')) {
                        $item[$this->getData('name')]['delete'] = [
                            'href' => $this->urlBuilder->getUrl(
                                static::URL_PATH_DELETE,
                                [
                                    'entity_id' => $item['entity_id']
                                ]
                            ),
                            'label' => __('Delete'),
                            'confirm' => [
                                'title' => __('Delete ${ $.$data.name }'),
                                'message' => __('Are you sure you wan\'t to delete a ${ $.$data.name } record?')
                            ]

                        ];
                    }
                }
            }
        }
        
        return $dataSource;
    }
}
