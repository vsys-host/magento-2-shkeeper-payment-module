<?php

namespace Shkeeper\Gateway\Controller\Webhook;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\OrderFactory;
use Magento\Quote\Model\QuoteFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\DB\Transaction as DbTransaction;
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
    protected $_dbTransaction;

    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        LoggerInterface $logger,
        OrderRepositoryInterface $orderRepository,
        OrderFactory $orderFactory,
        QuoteFactory $quoteFactory,
        ShkeeperHelper $shkeeperHelper,
        BuilderInterface $transactionBuilder,
        DbTransaction $dbTransaction,
    ) {
        $this->_request = $request;
        $this->_jsonFactory = $jsonFactory;
        $this->_logger = $logger;
        $this->_orderRepository = $orderRepository;
        $this->_orderFactory = $orderFactory;
        $this->_quoteFactory = $quoteFactory;
        $this->_shkeeperHelper = $shkeeperHelper;
        $this->_transactionBuilder = $transactionBuilder;
        $this->_dbTransaction = $dbTransaction;
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
            $this->_logger->error('Error reading Shkeeper webhook request: ' . $exception->getMessage());
            return $this->reject($result, 400, 'Bad Request.');
        }

        // validate APIKey is present
        if (!$shkeeperAPIKey) {
            $this->_logger->warning('Shkeeper webhook rejected: API key missing.');
            return $this->reject($result, 403, 'Shkeeper API Key is required.');
        }

        // validate APIKey matches the configured key (constant-time)
        if (!hash_equals((string) $this->_shkeeperHelper->getApiKey(), (string) $shkeeperAPIKey)) {
            $this->_logger->warning('Shkeeper webhook rejected: API key mismatch.', [
                'supplied_fp' => substr(hash('sha256', (string) $shkeeperAPIKey), 0, 8),
                'remote_ip'   => $this->_request->getClientIp(),
            ]);
            return $this->reject($result, 403, 'Wrong Shkeeper API Key.');
        }

        // validate request payload
        if (!json_validate((string) $postData)) {
            $this->_logger->warning('Shkeeper webhook rejected: invalid JSON payload.');
            return $this->reject($result, 400, 'Payload is invalid.');
        }

        $payload = json_decode((string) $postData, true);
        if (!is_array($payload) || empty($payload['external_id'])) {
            $this->_logger->warning('Shkeeper webhook rejected: missing external_id.');
            return $this->reject($result, 400, 'Payload is invalid.');
        }

        // collect order object
        try {
            $order = $this->getOrderByQuoteId($payload['external_id']);
        } catch (\Exception $exception) {
            $this->_logger->error('Error loading Shkeeper webhook order: ' . $exception->getMessage());
            return $this->reject($result, 404, 'Invalid Reference Order.');
        }

        if (!$order || !$order->getId()) {
            $this->_logger->warning('Shkeeper webhook rejected: order not found.', [
                'external_id' => $payload['external_id'],
            ]);
            return $this->reject($result, 404, 'Invalid Reference Order.');
        }

        // Generate comment + register the triggering transactions
        $comment = '';
        foreach ($payload['transactions'] ?? [] as $transaction) {
            if (!empty($transaction['trigger'])) {
                // Add payment comment
                $comment .= 'TransactionId: ' . ($transaction['txid'] ?? '')
                    . ', Amount: ' . ($transaction['amount_crypto'] ?? '')
                    . ' ' . ($transaction['crypto'] ?? '') . PHP_EOL;

                // Add payment transaction
                $this->addPaymentTransaction($order, $transaction);
            }
        }

        // Add the comment to the order
        if ($comment !== '') {
            $order->addStatusHistoryComment($comment)
                ->setIsVisibleOnFront(true) // Visible to customer
                ->setIsCustomerNotified(true); // send an email
        }

        try {
            // When the callback reports the invoice as fully paid, create a real Magento
            // invoice. This marks the order paid, populates the Invoices grid, enables
            // credit memos and lets Magento drive the state transition. canInvoice() makes
            // a repeated/duplicate webhook idempotent (it won't invoice twice).
            $invoice = null;
            if (!empty($payload['paid']) && $order->canInvoice()) {
                $invoice = $order->prepareInvoice();
                $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
                $invoice->register();

                
                $processingStatus = $order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING);
                $order->setState(Order::STATE_PROCESSING)
                    ->setStatus($processingStatus ?: Order::STATE_PROCESSING);
            }

            // Note overpayments on every fully-paid callback (including later ones that
            // arrive after the order is already invoiced), so a surplus that keeps
            // growing is reflected. addOverpaymentComment() is self-deduplicating.
            if (!empty($payload['paid'])) {
                $this->addOverpaymentComment($order, $payload);
            }

            if ($invoice !== null) {
                // Persist the new invoice and the order (comments included) atomically.
                $this->_dbTransaction
                    ->addObject($invoice)
                    ->addObject($order)
                    ->save();
            } else {
                // Partial payment, or already invoiced: just persist comments/transactions.
                $this->_orderRepository->save($order);
            }
        } catch (\Exception $exception) {
            $this->_logger->error('Error saving Shkeeper webhook order updates: ' . $exception->getMessage());
            return $this->reject($result, 500, 'Could not update order.');
        }

        $result->setHttpResponseCode(202);
        $result->setData(['message' => 'Order Updated.']);
        $result->setHeader('Content-Type', 'application/json', true);

        return $result;
    }

    /**
     * Build an early-return JSON rejection response.
     *
     * @param \Magento\Framework\Controller\Result\Json $result
     * @param int $code
     * @param string $message
     * @return \Magento\Framework\Controller\Result\Json
     */
    private function reject($result, int $code, string $message)
    {
        $result->setHttpResponseCode($code);
        $result->setData(['message' => $message]);
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

    /**
     * Record an overpayment note when SHKeeper reports a surplus that exceeds the
     * configured margin. Self-deduplicating: the last surplus already noted is stored
     * on the payment, so the same overpayment isn't reported twice, while a surplus
     * that keeps growing across later callbacks adds a fresh, updated note.
     *
     * @param \Magento\Sales\Model\Order $order
     * @param array $payload
     * @return void
     */
    private function addOverpaymentComment($order, array $payload): void
    {
        // Use SHKeeper's own fee-adjusted surplus rather than recomputing from
        // amount_fiat (which includes the gateway fee).
        $overpaid = (float) ($payload['overpaid_fiat'] ?? 0);
        if ($overpaid <= 0.00000001) {
            return;
        }

        // Margin: a % of the order total, to suppress trivial rounding surpluses.
        $tolerance = (float) $order->getBaseGrandTotal()
            * ($this->_shkeeperHelper->getOverpaymentMargin() / 100);
        if ($overpaid <= $tolerance) {
            return;
        }

      
        $payment = $order->getPayment();
        $lastNoted = (float) $payment->getAdditionalInformation('shkeeper_overpaid_noted');
        if ($overpaid <= $lastNoted + 0.00000001) {
            return;
        }

        $order->addStatusHistoryComment(
            __(
                'Overpayment received: %1 %2 above the order total. '
                . 'No automatic credit is issued; ',
                number_format($overpaid, 2),
                (string) ($payload['fiat'] ?? $order->getBaseCurrencyCode())
            )
        )->setIsVisibleOnFront(true);

        $payment->setAdditionalInformation('shkeeper_overpaid_noted', $overpaid);
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
