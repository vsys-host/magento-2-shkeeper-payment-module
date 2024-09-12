<?php

namespace Shkeeper\Gateway\Controller\Webhook;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Shkeeper\Gateway\Model\ShkeeperHelper;

class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    protected $_request;
    protected $_jsonFactory;
    protected $_logger;
    protected $_orderRepository;
    protected $_orderFactory;
    protected $_quoteFactory;
    protected $_shkeeperHelper;
    protected $_transactionBuilder;

    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        LoggerInterface $logger,
        OrderRepositoryInterface $orderRepository,
        OrderFactory $orderFactory,
        QuoteFactory $quoteFactory,
        ShkeeperHelper $shkeeperHelper,
        BuilderInterface $transactionBuilder,
    ) {
        $this->_request = $request;
        $this->_jsonFactory = $jsonFactory;
        $this->_logger = $logger;
        $this->_orderRepository = $orderRepository;
        $this->_orderFactory = $orderFactory;
        $this->_quoteFactory = $quoteFactory;
        $this->_shkeeperHelper = $shkeeperHelper;
        $this->_transactionBuilder = $transactionBuilder;
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {

        $result = $this->_jsonFactory->create();

        // collect payload
        try {
            $shkeeperAPIKey = $this->_request->getHeader('X-Shkeeper-Api-Key');
            $postData = $this->_request->getContent();
        } catch (\Exception $exception) {
            $this->_logger->error('Error processing webhook request: ' . $exception->getMessage());
        }

        // validate APIKey
        if (!$shkeeperAPIKey) {

            $result->setData(['message' => 'Shkeeper API Key is required.']);
            $this->_logger->debug('Shkeeper API Key is required.');
            $result->setHttpResponseCode(403);

            return $result;
        }

        // validate APIKey is identical with store APIKey
        if ($shkeeperAPIKey != $this->_shkeeperHelper->getApiKey()) {

            $result->setData(['message' => 'Wrong Shkeeper API Key.']);
            $this->_logger->debug('Wrong Shkeeper API Key.', ['callback' => $shkeeperAPIKey, 'configured' => $this->_shkeeperHelper->getApiKey()]);
            $result->setHttpResponseCode(403);

            return $result;
        }

        // validate request payload
        if (! json_validate($postData)) {
            $result->setData(['message' => 'Payload is invalid.']);
            $this->_logger->debug('Payload is invalid.');
            $result->setHttpResponseCode(400);
            return $result;
        }

        // collect order object
        try {

            $payload = json_decode($postData, true);
            $order = $this->getOrderByQuoteId($payload['external_id']);

        } catch (\Exception $exception) {
            $this->_logger->error('Error processing webhook request: ' . $exception->getMessage());
        }

        if (!$order->getId()) {
            $result->setData(['message' => 'Invalid Reference Order.']);
            $this->_logger->debug('Invalid Reference Order. ', $payload);
            $result->setHttpResponseCode(404);
            return $result;
        }

        // calculate paid amount
        $amount = $this->getTotalPaidAmount($payload);

        // update order total paid
        $order->setTotalInvoiced($amount);
        $order->setTotalPaid($amount);
        $order->setBaseTotalInvoiced($amount);

        // change payment state when all amount paid
        if ( $payload['paid']) {
            $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
            $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
        }

        // Generate comment
        $comment = '';

        foreach ($payload['transactions'] as $transaction) {
            if ($transaction['trigger']) {
                // Add payment comment
                $comment .= 'TransactionId: ' . $transaction['txid'] . ', Amount: ' . $transaction['amount_crypto'] . ' ' . $transaction['crypto'] . PHP_EOL;

                // Add payment transaction
                $this->addPaymentTransaction($order, $transaction);
            }
        }

        // Add the comment to the order
        $order->addStatusHistoryComment($comment)
            ->setIsVisibleOnFront(true) // Visible to customer
            ->setIsCustomerNotified(true); // send an email

        try {
            $order->save();
        } catch (\Exception $exception) {
            $this->_logger->error('Error processing save order updates: ' . $exception->getMessage());
        }

        $result->setHttpResponseCode(202);
        $result->setData(['message' => 'Order Updated.'], true);
        $result->setHeader('Content-Type', 'application/json', true);

        return $result;
    }

    /**
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    private function getOrderByQuoteId($quoteId)
    {

        // fetch OrderId
        $quoteFactory = $this->_quoteFactory->create();
        $quote = $quoteFactory->load($quoteId);
        $orderId = $quote->getReservedOrderId();

        // get order object
        $orderFactory = $this->_orderFactory->create();
        return $orderFactory->loadByIncrementId($orderId);
    }

    private function getTotalPaidAmount(array $payload): string
    {
        $amount = 0;

        foreach ($payload['transactions'] as $transaction) {
            $amount += $transaction['amount_fiat'];
        }

        return $amount;
    }

    private function addPaymentTransaction($order, $transactionData)
    {
        try {
            $payment = $order->getPayment();

            $transaction = $this->_transactionBuilder
                ->setPayment($payment)
                ->setOrder($order)
                ->setTransactionId($transactionData['txid'])
                ->setAdditionalInformation(
                    [Transaction::RAW_DETAILS => $transactionData]
                )
                ->setFailSafe(true)
                ->build(Transaction::TYPE_CAPTURE);

            $payment->addTransactionCommentsToOrder($transaction, __('Transaction was added by Shkeeper webhook.'));
            $transaction->save();
        } catch (\Exception $e) {
            $this->_logger->error('Error adding payment transaction: ' . $e->getMessage());
        }
    }

}
