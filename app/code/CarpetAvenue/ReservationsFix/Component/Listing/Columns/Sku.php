<?php

namespace CarpetAvenue\ReservationsFix\Component\Listing\Columns;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\UrlInterface;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;

class Sku extends Column{
    /**
     * @var UrlInterface
     */
    private $urlBuilder;
    protected ProductRepositoryInterface $productRepository;

    /**
     * Order Id constructor.
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $urlBuilder
     * @param ProductRepositoryInterface $productRepository
     * @param string[] $components
     * @param string[] $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        UrlInterface $urlBuilder,
        ProductRepositoryInterface $productRepository,
        array $components = [],
        array $data = []
    ) {
        $this->urlBuilder = $urlBuilder;
        $this->productRepository = $productRepository;
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as & $item) {
                if (isset($item['sku'])) {
                    try{
                        $product = $this->productRepository->get($item['sku']);
                        $url = $this->urlBuilder->getUrl('catalog/product/edit', ['id' => $product->getId()]);
                        $link = '<a target="_blank" href="' . $url . '"">' . $item['sku'] . '</a>';
                        $item['sku'] = $link;
                    }
                    catch (NoSuchEntityException $e) {
                        //??
                    }
                }
            }
        }
        return $dataSource;
    }
}