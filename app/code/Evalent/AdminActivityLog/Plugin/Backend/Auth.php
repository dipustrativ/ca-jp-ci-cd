<?php

namespace Evalent\AdminActivityLog\Plugin\Backend;

use Evalent\AdminActivityLog\Api\LoginActivityRepositoryInterface;
use Evalent\AdminActivityLog\Model\LoginActivity;
use Evalent\AdminActivityLog\Model\LoginActivityFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Psr\Log\LoggerInterface;

class Auth
{

    const LOGGER_PREFIX = "Logout after event: ";

    /**
     * @var \Magento\Backend\Model\Auth\Session
     */
    private $authSession;

    /**
     * @var \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress
     */
    private $remoteAddress;

    /**
     * @var \Evalent\AdminActivityLog\Model\LoginActivityFactory
     */
    private $activityFactory;

    /**
     * @var \Evalent\AdminActivityLog\Api\LoginActivityRepositoryInterface
     */
    private $activityRepository;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * Auth constructor.
     *
     * @param \Evalent\AdminActivityLog\Model\LoginActivityFactory           $activityFactory
     * @param \Evalent\AdminActivityLog\Api\LoginActivityRepositoryInterface $activityRepository
     * @param \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress           $remoteAddress
     * @param \Magento\Backend\Model\Auth\Session                            $authSession
     * @param \Psr\Log\LoggerInterface                                       $logger
     */
    public function __construct(
        LoginActivityFactory $activityFactory,
        LoginActivityRepositoryInterface $activityRepository,
        RemoteAddress $remoteAddress,
        \Magento\Backend\Model\Auth\Session $authSession,
        LoggerInterface $logger
    ) {
        $this->authSession = $authSession;
        $this->remoteAddress = $remoteAddress;
        $this->activityFactory = $activityFactory;
        $this->activityRepository = $activityRepository;
        $this->logger = $logger;
    }


    public function beforeLogout(\Magento\Backend\Model\Auth $authModel)
    {
        $user = $this->authSession->getUser();

        /** @var \Evalent\AdminActivityLog\Model\LoginActivity $loginActivity */
        $loginActivity = $this->activityFactory->create();

        $loginActivity->setUsername($user->getUserName());
        $loginActivity->setStatus(LoginActivity::LOGOUT);
        $loginActivity->setIpAddress($this->remoteAddress->getRemoteAddress());

        try {
            $this->activityRepository->save($loginActivity);
        } catch (\Exception $e) {
            $this->logger->error(self::LOGGER_PREFIX . 'Was unable to log the event: ' . $e->getMessage());
        }
    }
}