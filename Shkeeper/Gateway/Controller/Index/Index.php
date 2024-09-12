<?php

namespace Shkeeper\Gateway\Controller\Index;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Shkeeper\Gateway\Model\ShkeeperHelper;

class Index implements HttpPostActionInterface
{
    protected JsonFactory $_jsonFactory;
    protected Context $_context;
    protected RequestInterface $_request;
    protected ShkeeperHelper $_shkeeperHelper;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        RequestInterface $request,
        ShkeeperHelper $shkeeperHelper
    ) {
        $this->_context = $context;
        $this->_jsonFactory = $jsonFactory;
        $this->_request = $request;
        $this->_shkeeperHelper = $shkeeperHelper;
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {

        // fetch currencies
        $request = $this->_shkeeperHelper->getAvailableCurrencies();
        $data = json_decode($request, 'true', 512, JSON_INVALID_UTF8_IGNORE);

        // render page
        $result = $this->_jsonFactory->create();
        $result->setData($data);
        $result->setHttpResponseCode(200);

        return $result;
    }

}
