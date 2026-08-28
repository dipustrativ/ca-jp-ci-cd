<?php

namespace Evalent\AdminActivityLog\Observer\User;

use Evalent\AdminActivityLog\Api\LoginActivityRepositoryInterface;
use Evalent\AdminActivityLog\Model\LoginActivity;
use Evalent\AdminActivityLog\Api\Data\LoginActivityInterfaceFactory as LoginActivityFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Psr\Log\LoggerInterface;

class AuthAfter implements \Magento\Framework\Event\ObserverInterface
{

    const LOGGER_PREFIX = "Auth after event: ";

    /**
     * @var \Evalent\AdminActivityLog\Model\LoginActivityInterfaceFactory
     */
    private $activityFactory;

    /**
     * @var \Evalent\AdminActivityLog\Api\LoginActivityRepositoryInterface
     */
    private $activityRepository;

    /**
     * @var \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress
     */
    private $remoteAddress;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * AuthAfter constructor.
     *
     * @param \Evalent\AdminActivityLog\Api\Data\LoginActivityInterfaceFactory $activityFactory
     * @param \Evalent\AdminActivityLog\Api\LoginActivityRepositoryInterface   $activityRepository
     * @param \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress             $remoteAddress
     * @param \Psr\Log\LoggerInterface                                         $logger
     */
    public function __construct(
        LoginActivityFactory $activityFactory,
        LoginActivityRepositoryInterface $activityRepository,
        RemoteAddress $remoteAddress,
        LoggerInterface $logger
    )
    {
        $this->activityFactory = $activityFactory;
        $this->activityRepository = $activityRepository;
        $this->remoteAddress = $remoteAddress;
        $this->logger = $logger;
    }

    /**
     * Execute observer
     *
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(
        \Magento\Framework\Event\Observer $observer
    ) {
        try {
            switch ($observer->getEvent()->getName()) {
                case "backend_auth_user_login_success":
                    $this->loginSuccess($observer);
                    break;
                case "backend_auth_user_login_failed":
                    $this->loginFail($observer);
                    break;
            }
        } catch (\Exception $e) {
            $this->logger->error(self::LOGGER_PREFIX . 'Was unable to log the event: ' . $e->getMessage());
        }
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function loginSuccess($observer)
    {
        /** @var \Magento\User\Model\User $user */
        $user = $observer->getData('user');

        /** @var \Evalent\AdminActivityLog\Model\LoginActivity $loginActivity */
        $loginActivity = $this->activityFactory->create();

        $loginActivity->setUsername($user->getUserName());
        $loginActivity->setStatus(LoginActivity::SUCCESSFUL_LOGIN);
        $loginActivity->setIpAddress($this->remoteAddress->getRemoteAddress());

        $this->activityRepository->save($loginActivity);
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function loginFail($observer)
    {
        $username = $observer->getData('user_name');
        /** @var \Exception $exception */
        $exception = $observer->getData('exception');

        /** @var \Evalent\AdminActivityLog\Model\LoginActivity $loginActivity */
        $loginActivity = $this->activityFactory->create();

        $loginActivity->setUsername($username);
        $loginActivity->setStatus(LoginActivity::FAILED_LOGIN);
        $loginActivity->setIpAddress($this->remoteAddress->getRemoteAddress());

        $this->activityRepository->save($loginActivity);
    }

}