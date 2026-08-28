<?php

declare(strict_types=1);

namespace Evalent\Banner\Ui\Component\Listing\Column;

use Evalent\Banner\Model\Banner\ImageUploader;
use Evalent\Banner\Model\UploaderPool;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class DesktopImage extends Column
{
    const NAME = 'desktop_image';

    /**
     * @var \Evalent\Banner\Model\UploaderPool
     */
    private $uploaderPool;

    /**
     * @param \Evalent\Banner\Model\UploaderPool $uploaderPool
     * @param ContextInterface                    $context
     * @param UiComponentFactory                  $uiComponentFactory
     * @param array                               $components
     * @param array                               $data
     */
    public function __construct(
        UploaderPool $uploaderPool,
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
        $this->uploaderPool = $uploaderPool;
    }

    /**
     * Prepare Data Source
     *
     * @param array $dataSource
     *
     * @return array
     * @throws \Exception
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (isset($dataSource['data']['items'])) {
            $fieldName = $this->getData('name');
            $uploader = $this->uploaderPool->getUploader('image');
            foreach ($dataSource['data']['items'] as & $item) {
                if (isset($item[self::NAME])) {
                    $item[$fieldName . '_src'] = $uploader->getBaseUrl() . $uploader->getBasePath() . $item[self::NAME][0]["image"];
                }
            }
        }

        return $dataSource;
    }
}