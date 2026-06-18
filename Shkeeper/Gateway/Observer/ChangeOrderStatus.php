<?php

namespace Shkeeper\Gateway\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

class ChangeOrderStatus implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Observer $observer)
    {
        // Get the order from the observer
        $order = $observer->getEvent()->getOrder();

        // Only act on SHKeeper orders
        if (!$order || !$order->getPayment()
            || $order->getPayment()->getMethod() !== 'shkeeper'
        ) {
            return;
        }

        // Set the initial state.
        $order->setState(Order::STATE_NEW);
        $order->setStatus(Order::STATE_NEW);
    }
}
