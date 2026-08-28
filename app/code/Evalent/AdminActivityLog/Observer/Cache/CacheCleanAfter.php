<?php

declare(strict_types=1);

namespace Evalent\AdminActivityLog\Observer\Cache;

use Evalent\AdminActivityLog\Api\CacheActivityRepositoryInterface;
use Evalent\AdminActivityLog\Api\Data\CacheActivityInterfaceFactory;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class CacheCleanAfter implements ObserverInterface
{

    /**
     * @var \Evalent\AdminActivityLog\Api\CacheActivityRepositoryInterface
     */
    private $cacheActivityRepository;

    /**
     * @var \Evalent\AdminActivityLog\Api\Data\CacheActivityInterfaceFactory
     */
    private $cacheActivityFactory;

    /**
     * @var \Magento\Backend\Model\Auth\Session
     */
    private $session;

    /**
     * CacheCleanAfter constructor.
     *
     * @param \Evalent\AdminActivityLog\Api\CacheActivityRepositoryInterface   $cacheActivityRepository
     * @param \Evalent\AdminActivityLog\Api\Data\CacheActivityInterfaceFactory $cacheActivityFactory
     * @param \Magento\Backend\Model\Auth\Session                              $session
     */
    public function __construct(
        CacheActivityRepositoryInterface $cacheActivityRepository,
        CacheActivityInterfaceFactory $cacheActivityFactory,
        Session $session
    ) {
        $this->cacheActivityRepository = $cacheActivityRepository;
        $this->cacheActivityFactory = $cacheActivityFactory;
        $this->session = $session;
    }

    /**
     * @param Observer $observer
     *
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function execute(Observer $observer)
    {
        $eventName = $observer->getEvent()->getName();
        /** @var \Evalent\AdminActivityLog\Api\Data\CacheActivityInterface $activity */
        $activity = $this->cacheActivityFactory->create();

        switch ($eventName) {
            case "clean_catalog_images_cache_after":
                $activity->setCacheType("Catalog images");
                $activity->setActionType("Clean");
                break;
            case "clean_media_cache_after":
                $activity->setCacheType("JavaScript/CSS");
                $activity->setActionType("Clean");
                break;
            case "clean_static_files_cache_after":
                $activity->setCacheType("Static files");
                $activity->setActionType("Clean");
                break;
            case "adminhtml_cache_flush_all":
                $activity->setCacheType("Cache storage");
                $activity->setActionType("Clean");
                break;
            case "adminhtml_cache_flush_system":
                $activity->setCacheType("Magento cache");
                $activity->setActionType("Clean");
                break;
        }

        $user = $this->session->getUser();
        $activity->setUsername($user->getUserName());
        $activity->setFullName($user->getName());
        $this->cacheActivityRepository->save($activity);
    }
}