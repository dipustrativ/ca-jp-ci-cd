<?php

namespace Evalent\OrderMods\Plugin;

class Order
{
    public function __construct(protected \Magento\Backend\Model\Auth\Session $authSession)
    {}
    public function beforeAddStatusHistoryComment($subject, $comment, $status = false)
    {
        try {
            $user = $this->authSession->getUser();
            if ($user) {
                $comment = ' | by ' . $user->getFirstname() . ' ' . $user->getLastname() . ' (' . $user->getUsername() . ')<br>' . $comment ;
            } else {
                $comment = ' | by Unknown User<br>' . $comment;
            }
        } catch (\Exception $e) {
            // If the authsession is not valid, we can just return the original values
            return [$comment, $status];
        }
        
        return [$comment, $status];
    }

    public function afterHold(\Magento\Sales\Model\Order $context, \Magento\Sales\Model\Order $order)
    {
        // Custom logic after holding an order.
        if ($this->authSession->isLoggedIn()) {
            $order->addStatusHistoryComment( 'Helded', false);
        }
        
        return $order;
    }
    public function afterUnhold(\Magento\Sales\Model\Order $context, \Magento\Sales\Model\Order $order)
    {
        // Custom logic after un-holding an order.
        if ($this->authSession->isLoggedIn()) {
            $order->addStatusHistoryComment( 'Unholded', false);
        }
        return $order;
    }
}