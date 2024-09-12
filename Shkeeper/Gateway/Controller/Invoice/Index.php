<?php

namespace Shkeeper\Gateway\Controller\Invoice;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Shkeeper\Gateway\Model\ShkeeperHelper;
use Magento\Checkout\Model\Session as CheckoutSession;

class Index implements HttpPostActionInterface
{
    protected JsonFactory $_jsonFactory;
    protected Context $_context;
    protected RequestInterface $_request;
    protected ShkeeperHelper $_shkeeperHelper;
    protected CheckoutSession $_checkoutSession;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        RequestInterface $request,
        ShkeeperHelper $shkeeperHelper,
        CheckoutSession $checkoutSession
    ) {
        $this->_context = $context;
        $this->_jsonFactory = $jsonFactory;
        $this->_request = $request;
        $this->_shkeeperHelper = $shkeeperHelper;
        $this->_checkoutSession = $checkoutSession;
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        // Get quote ID from the checkout session
        $quote = $this->_checkoutSession->getQuote();

        // Collecting request params
        $quoteId = $quote->getId();
        $currency = $quote->getBaseCurrencyCode();
        $amount = $quote->getBaseGrandTotal();
        $cryptoCurrency = $this->_request->getParam("crypto");

        // Send post request to generate invoice
        $request = $this->_shkeeperHelper->getInvoiceAddress(
            $quoteId,
            $currency,
            $amount,
            $cryptoCurrency
        );
        $data = json_decode($request, true, 512, JSON_INVALID_UTF8_IGNORE);

        // Render page
        $result = $this->_jsonFactory->create();
        $data['cart'] = [
            'quote_id' => $quoteId,
            'currency' => $currency,
            'amount' => $amount,
            'crypto' => $cryptoCurrency,
        ];
        $result->setData($data);
        $result->setHttpResponseCode(200);

        return $result;
    }
}
