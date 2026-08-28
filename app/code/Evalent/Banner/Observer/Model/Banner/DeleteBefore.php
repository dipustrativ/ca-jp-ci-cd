<?php

declare(strict_types=1);

namespace Evalent\Banner\Observer\Model\Banner;

use Magento\Backend\Model\Auth\Session;
use Magento\Framework\Authorization\PolicyInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;

class DeleteBefore implements ObserverInterface
{

    /**
     * @var \Magento\Backend\Model\Auth\Session
     */
    private $authSession;

    /**
     * @var \Magento\Framework\Authorization\PolicyInterface
     */
    private $policy;

    public function __construct(
        Session $authSession,
        PolicyInterface $policy
    ) {
        $this->authSession = $authSession;
        $this->policy = $policy;
    }

    /**
     * @param Observer $observer
     *
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function execute(Observer $observer)
    {
        $user = $this->authSession->getUser();
        if (!$user) {
            return;
        }
        if (!$this->policy->isAllowed($user->getRole()->getId(),
            'Evalent_Banner::Banner_delete')
        ) {
            throw new LocalizedException(__("You dont have the permission to delete banners"));
        }
    }
}