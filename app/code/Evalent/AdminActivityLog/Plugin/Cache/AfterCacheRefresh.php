<?php

declare(strict_types=1);

namespace Evalent\AdminActivityLog\Plugin\Cache;

use Evalent\AdminActivityLog\Api\CacheActivityRepositoryInterface;
use Evalent\AdminActivityLog\Api\Data\CacheActivityInterfaceFactory;
use Magento\Backend\Controller\Adminhtml\Cache\MassRefresh;
use Magento\Backend\Model\Auth\Session;

class AfterCacheRefresh
{

    const HUMAN_READABLE_CACHE
        = [
            'config'                  => 'Config',
            'layout'                  => 'Layout building instructions ',
            'block_html'              => 'Page blocks HTML ',
            'collections'             => 'Collection data files ',
            'reflection'              => 'API interfaces reflection data ',
            'db_ddl'                  => 'Results of DDL queries, such as describing tables or indexes ',
            'eav'                     => 'Entity types declaration cache ',
            'customer_notification'   => 'Customer Notification ',
            'config_integration'      => 'Integration configuration file ',
            'config_integration_api,' => 'Integrations API configuration file ',
            'full_page'               => 'Full page caching',
            'translate'               => 'Translation files ',
            'config_webservice'       => 'REST and SOAP configurations, generated WSDL file ',
            'vertex'                  => 'Vertex tax calculation data ',
        ];

    /**
     * @var \Magento\Backend\Model\Auth\Session
     */
    private $session;

    /**
     * @var \Evalent\AdminActivityLog\Api\CacheActivityRepositoryInterface
     */
    private $cacheActivityRepository;

    /**
     * @var \Evalent\AdminActivityLog\Api\Data\CacheActivityInterfaceFactory
     */
    private $cacheActivityFactory;

    /**
     * AfterCacheRefresh constructor.
     *
     * @param \Magento\Backend\Model\Auth\Session                              $session
     * @param \Evalent\AdminActivityLog\Api\CacheActivityRepositoryInterface   $cacheActivityRepository
     * @param \Evalent\AdminActivityLog\Api\Data\CacheActivityInterfaceFactory $cacheActivityFactory
     */
    public function __construct(
        Session $session,
        CacheActivityRepositoryInterface $cacheActivityRepository,
        CacheActivityInterfaceFactory $cacheActivityFactory
    ) {
        $this->session = $session;
        $this->cacheActivityRepository = $cacheActivityRepository;
        $this->cacheActivityFactory = $cacheActivityFactory;
    }

    /**
     * @param \Magento\Backend\Controller\Adminhtml\Cache\MassRefresh $subject
     */
    public function beforeExecute(MassRefresh $subject)
    {
        $types = $subject->getRequest()->getParam('types');
        if (!is_array($types)) {
            $types = [];
        }
        $user = $this->session->getUser();
        foreach ($types as $type) {
            /** @var \Evalent\AdminActivityLog\Api\Data\CacheActivityInterface $activity */
            $activity = $this->cacheActivityFactory->create();
            $activity->setUsername($user->getUserName());
            $activity->setFullName($user->getName());
            $activity->setCacheType(isset(self::HUMAN_READABLE_CACHE[$type]) ? self::HUMAN_READABLE_CACHE[$type] : $type);
            $activity->setActionType("Clean");
            $this->cacheActivityRepository->save($activity);
        }
    }
}