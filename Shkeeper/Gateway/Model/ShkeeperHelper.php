<?php

namespace Shkeeper\Gateway\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class ShkeeperHelper implements ConfigProviderInterface
{
    protected const XML_PATH_SHKEEPER_API_KEY = 'payment/shkeeper/shkeeper_api_key';
    protected const XML_PATH_SHKEEPER_API_URL = 'payment/shkeeper/shkeeper_api_url';
    protected const XML_PATH_SHKEEPER_INSTRUCTIONS = 'payment/shkeeper/instructions';
    protected const XML_PATH_SECURE_BASE_URL = 'web/secure/base_url';
    protected const SHKEEPER_CODE = 'shkeeper';

    /**
     * @var StoreManagerInterface $_storeManager
     */
    protected StoreManagerInterface $_storeManager;
    /**
     * @var ScopeConfigInterface $_scopeConfig
     */
    protected ScopeConfigInterface $_scopeConfig;
    /**
     * @var Curl $_curl
     */
    protected Curl $_curl;
    protected LoggerInterface $_logger;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Curl $curl
     * @param LoggerInterface $logger
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Curl $curl,
        LoggerInterface $logger,
    ) {
        $this->_scopeConfig = $scopeConfig;
        $this->_curl = $curl;
        $this->_logger = $logger;
    }

    public function getCode(): string
    {
        return self::SHKEEPER_CODE;
    }

    public function getApiKey(): string
    {
        return $this->_scopeConfig->getValue(self::XML_PATH_SHKEEPER_API_KEY, ScopeInterface::SCOPE_STORE);
    }

    public function getApiURL(): string
    {
        return $this->_scopeConfig->getValue(self::XML_PATH_SHKEEPER_API_URL, ScopeInterface::SCOPE_STORE);
    }

    public function getInstructions(): string
    {
        return $this->_scopeConfig->getValue(self::XML_PATH_SHKEEPER_INSTRUCTIONS, ScopeInterface::SCOPE_STORE);
    }

    public function getCallbackUrl(): string
    {
        $baseURL = $this->_scopeConfig->getValue(self::XML_PATH_SECURE_BASE_URL, ScopeInterface::SCOPE_STORE);
        return $baseURL . 'shkeeper/webhook';
    }

    public function getConfig(): array
    {
        return [
            'payment' => [
                $this->getCode() => [
                    'apiKey' => $this->getApiKey(),
                    'apiUrl' => $this->getApiURL(),
                    'instructions' => $this->getInstructions(),
                    'callback_url'  => $this->getCallbackURL(),
                ]
            ]
        ];
    }

    public function getInvoiceAddress($externalId, $currency, $amount, $cryptoCurrency)
    {
        try {
            // Headers
            $headers = [
                'X-Shkeeper-Api-Key' => $this->getApiKey(),
                'Content-Type' => 'application/json',
            ];

            // Parameters
            $params = [
                'external_id' => $externalId,
                'fiat' => $currency,
                'amount' => $amount,
                'callback_url' => $this->getCallbackUrl(),
            ];

            // Convert parameters to JSON
            $jsonParams = json_encode($params);

            $url = $this->addURLSchema($this->getApiURL());
            $url = $this->addURLSeparator($url);
            $url = $url . $cryptoCurrency . '/payment_request';

            // Initialize cURL session
            $ch = curl_init($url);

            if ($ch === false) {
                throw new \Exception('Failed to initialize cURL session.');
            }

            $this->_curl->setHeaders($headers);
            $this->_curl->post($url, $jsonParams);

            return $this->_curl->getBody();

        } catch (\Exception $e) {
            // Log the error
            $this->_logger->error('Error in getInvoiceAddress: ' . $e->getMessage());
            throw $e;
        }
    }


    public function getAvailableCurrencies()
    {
        try {
            // Headers
            $headers = [
                'X-Shkeeper-Api-Key: ' . $this->getApiKey(),
                'Content-Type: application/json',
            ];

            $url = $this->addURLSchema($this->getApiURL());
            $url = $this->addURLSeparator($url);
            $url = $url . 'crypto';

            $this->_curl->setHeaders($headers);
            $this->_curl->get($url);

            return $this->_curl->getBody();
        } catch (\Exception $e) {
            // Log the error
            $this->_logger->error('Error in getAvailableCurrencies: ' . $e->getMessage());
            throw $e;
        }
    }

    public function addURLSeparator(string $url): string
    {
        if (!str_ends_with($url, "/")) {
            return $url .= DIRECTORY_SEPARATOR;
        }

        return $url;
    }

    /**
     * Validate adding schema at the start of the link
     * @param string $url
     * @return string
     */
    public function addURLSchema(string $url): string
    {
        if (!str_contains($url, "http")) {
            return "https://" . $url;
        }

        return $url;
    }

}
