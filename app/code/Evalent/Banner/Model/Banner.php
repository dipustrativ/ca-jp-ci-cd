<?php

declare(strict_types=1);

namespace Evalent\Banner\Model;

use Evalent\Banner\Api\Data\BannerInterfaceFactory;
use Evalent\Banner\Api\Data\BannerInterface;
use Evalent\Banner\Model\ResourceModel\Banner\Collection;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\DataObject\IdentityInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;

class Banner extends AbstractModel implements IdentityInterface
{

    const CACHE_TAG = 'evalent_banner_banner';

    const ENTITY = 'evalent_banner_banner_entity';

    protected $dataObjectHelper;

    protected $bannerDataFactory;

    protected $_eventPrefix = 'evalent_banner_banner';

    /**
     * @var string
     */
    protected $_cacheTag = 'evalent_banner_banner';

    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param BannerInterfaceFactory $bannerDataFactory
     * @param DataObjectHelper $dataObjectHelper
     * @param \Evalent\Banner\Model\ResourceModel\Banner $resource
     * @param \Evalent\Banner\Model\ResourceModel\Banner\Collection $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        BannerInterfaceFactory $bannerDataFactory,
        DataObjectHelper $dataObjectHelper,
        ResourceModel\Banner $resource,
        Collection $resourceCollection,
        array $data = []
    ) {
        $this->bannerDataFactory = $bannerDataFactory;
        $this->dataObjectHelper = $dataObjectHelper;
        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    /**
     * Retrieve banner model with banner data
     * @return \Evalent\Banner\Api\Data\BannerInterface
     */
    public function getDataModel()
    {
        $bannerData = $this->getData();
        
        $bannerDataObject = $this->bannerDataFactory->create();
        $this->dataObjectHelper->populateWithArray(
            $bannerDataObject,
            $bannerData,
            BannerInterface::class
        );
        
        return $bannerDataObject;
    }

    /**
     * Get identities
     *
     * @return array
     */
    public function getIdentities()
    {
        return [self::CACHE_TAG . '_' . $this->getId()];
    }
}
