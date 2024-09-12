<?php

namespace Shkeeper\Gateway\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Magento\Sales\Model\Order;

class AddOrderComment implements ObserverInterface
{
    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();

        if (!$order || !$order->getPayment()) {
            $this->logger->error('Order or payment is missing in the observer event.');
            return;
        }

        $payment = $order->getPayment();
        $additionalData = $payment->getAdditionalInformation();

        $address = $additionalData['wallet'];
        $amount = $additionalData['amount'];

        // Add the comment to the order
        $comment = __('Customer Address: %1 and Order Amount: %2.', (string) $address, (string) $amount);
        $order->addStatusHistoryComment($comment)
            ->setIsVisibleOnFront(true) // Visible to customer
            ->setIsCustomerNotified(true); // send an email

        try {
            $this->orderRepository->save($order);
        } catch (\Exception $e) {
            $this->logger->error('Error saving order comment: ' . $e->getMessage());
        }
    }
}
