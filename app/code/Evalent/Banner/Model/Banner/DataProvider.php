<?php

declare(strict_types=1);

namespace Evalent\Banner\Model\Banner;

use Evalent\Banner\Model\UploaderPool;
use Magento\Framework\App\Request\DataPersistorInterface;
use Evalent\Banner\Model\ResourceModel\Banner\CollectionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Magento\Ui\DataProvider\Modifier\ModifierInterface;

class DataProvider extends AbstractDataProvider
{

    protected $collection;

    protected $dataPersistor;

    protected $loadedData;

    /**
     * @var \Evalent\Banner\Model\UploaderPool
     */
    private $uploaderPool;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    private $request;


    /**
     * Constructor
     *
     * @param \Evalent\Banner\Model\UploaderPool      $uploaderPool
     * @param string                                  $name
     * @param string                                  $primaryFieldName
     * @param string                                  $requestFieldName
     * @param CollectionFactory                       $collectionFactory
     * @param DataPersistorInterface                  $dataPersistor
     * @param \Magento\Framework\App\RequestInterface $request
     * @param array                                   $meta
     * @param array                                   $data
     */
    public function __construct(
        UploaderPool $uploaderPool,
        $name,
        $primaryFieldName,
        $requestFieldName,
        CollectionFactory $collectionFactory,
        DataPersistorInterface $dataPersistor,
        RequestInterface $request,
        array $meta = [],
        array $data = []
    ) {
        $this->collection = $collectionFactory->create();
        $this->dataPersistor = $dataPersistor;
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->uploaderPool = $uploaderPool;
        $this->request = $request;
    }

    /**
     * Get data
     *
     * @return array
     * @throws \Exception
     */
    public function getData()
    {
        if (isset($this->loadedData)) {
            return $this->loadedData;
        }
        $this->loadedData = [];
        $storeId = (int) $this->request->getParam('store');
        $this->collection->addFieldToSelect('*');
        $this->collection->setStore($storeId);
        $items = $this->collection->getItems();
        foreach ($items as $model) {
            $data = $model->getData();
            $uploader = $this->uploaderPool->getUploader('image');
            if (isset($data['desktop_image']) && $data['desktop_image']) {
                $img = [];
                $img[0]['image'] = $data['desktop_image'];
                $img[0]['url'] = $uploader->getBaseUrl() . $uploader->getBasePath(). $data['desktop_image'];
                $data['desktop_image'] = $img;
            }
            if (isset($data['mobile_image']) && $data['mobile_image']) {
                $img = [];
                $img[0]['image'] = $data['mobile_image'];
                $img[0]['url'] = $uploader->getBaseUrl() . $uploader->getBasePath(). $data['mobile_image'];
                $data['mobile_image'] = $img;
            }
            $this->loadedData[] = $data;
        }

        return [
            'items' => $this->loadedData,
            "totalRecords" => sizeof($this->loadedData)
            ];
    }
}
