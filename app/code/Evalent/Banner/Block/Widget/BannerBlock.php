<?php

declare(strict_types=1);

namespace Evalent\Banner\Block\Widget;

use Evalent\Banner\Api\BannerRepositoryInterface;
use Evalent\Banner\Model\ResourceModel\Banner\CollectionFactory;
use Magento\Framework\View\Element\Template;
use Magento\Widget\Block\BlockInterface;
use Psr\Log\LoggerInterface;

abstract class BannerBlock extends Template implements BlockInterface
{


    /**
     * @var \Evalent\Banner\Model\ResourceModel\Banner\CollectionFactory
     */
    protected $bannerCollectionFactory;

    /**
     * @var \Evalent\Banner\Api\BannerRepositoryInterface
     */
    protected $bannerRepository;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * BannerBlock constructor.
     *
     * @param \Psr\Log\LoggerInterface                                     $logger
     * @param \Evalent\Banner\Model\ResourceModel\Banner\CollectionFactory $bannerCollectionFactory
     * @param \Evalent\Banner\Api\BannerRepositoryInterface                $bannerRepository
     * @param \Magento\Framework\View\Element\Template\Context             $context
     * @param array                                                        $data
     */
    public function __construct(
        LoggerInterface $logger,
        CollectionFactory $bannerCollectionFactory,
        BannerRepositoryInterface $bannerRepository,
        Template\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->bannerCollectionFactory = $bannerCollectionFactory;
        $this->bannerRepository = $bannerRepository;
        $this->logger = $logger;
    }
}