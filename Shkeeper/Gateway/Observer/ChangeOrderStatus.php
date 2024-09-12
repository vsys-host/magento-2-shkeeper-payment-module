<?php

namespace Shkeeper\Gateway\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Framework\App\Config\ScopeConfigInterface;

class ChangeOrderStatus implements ObserverInterface
{

    protected $_scopeConfig;
    protected $_order;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Order $order
    ) {
        $this->_scopeConfig = $scopeConfig;
        $this->_order = $order;
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer)
    {
        // Get the order from the observer
        $order = $observer->getEvent()->getOrder();

        // Set the order status to the custom status
        $order->setState(Order::STATE_NEW);
        $order->setStatus(Order::STATE_NEW);
        $order->save();

    }
}
