<?php
namespace CarpetAvenue\ReservationsFix\Model;

class Session extends \Magento\Framework\Session\SessionManager{
    protected \Magento\Framework\Session\Generic $_session;
    public function __construct(
        \Magento\Framework\App\Request\Http $request,
        \Magento\Framework\Session\SidResolverInterface $sidResolver,
        \Magento\Framework\Session\Config\ConfigInterface $sessionConfig,
        \Magento\Framework\Session\SaveHandlerInterface $saveHandler,
        \Magento\Framework\Session\ValidatorInterface $validator,
        \Magento\Framework\Session\StorageInterface $storage,
        \Magento\Framework\Stdlib\CookieManagerInterface $cookieManager,
        \Magento\Framework\Stdlib\Cookie\CookieMetadataFactory $cookieMetadataFactory,
        \Magento\Framework\App\Http\Context $httpContext,
        \Magento\Framework\App\State $appState,
        \Magento\Framework\Session\Generic $session
        //\Magento\Framework\Event\ManagerInterface $eventManager,
        //\Magento\Framework\App\Response\Http $response
    ) {

        $this->_session = $session;
        //$this->_eventManager = $eventManager;
 
        parent::__construct(
            $request,
            $sidResolver,
            $sessionConfig,
            $saveHandler,
            $validator,
            $storage,
            $cookieManager,
            $cookieMetadataFactory,
            $appState
        );
        //$this->response = $response;
        //$this->_eventManager->dispatch('sessionname_session_init', ['sessionname_session' => $this]);
    }
}