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
    protected const XML_PATH_SHKEEPER_OVERPAYMENT_MARGIN = 'payment/shkeeper/overpayment_margin';
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
     * @var \Magento\Framework\Encryption\EncryptorInterface $_encryptor
     */
    protected \Magento\Framework\Encryption\EncryptorInterface $_encryptor;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Curl $curl
     * @param LoggerInterface $logger
     * @param \Magento\Framework\Encryption\EncryptorInterface $encryptor
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Curl $curl,
        LoggerInterface $logger,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
    ) {
        $this->_scopeConfig = $scopeConfig;
        $this->_curl = $curl;
        $this->_logger = $logger;
        $this->_encryptor = $encryptor;
    }

    public function getCode(): string
    {
        return self::SHKEEPER_CODE;
    }

    public function getApiKey(): string
    {
        $value = (string) $this->_scopeConfig->getValue(self::XML_PATH_SHKEEPER_API_KEY, ScopeInterface::SCOPE_STORE);

        return $value !== '' ? $this->_encryptor->decrypt($value) : '';
    }

    public function getApiURL(): string
    {
        return $this->_scopeConfig->getValue(self::XML_PATH_SHKEEPER_API_URL, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Overpayment tolerance, as a percentage of the order grand total. A surplus
     * smaller than this share is treated as rate/fee rounding and not flagged.
     * Clamped to a non-negative value; 0 means every surplus is reported.
     *
     * @return float
     */
    public function getOverpaymentMargin(): float
    {
        $margin = (float) $this->_scopeConfig->getValue(
            self::XML_PATH_SHKEEPER_OVERPAYMENT_MARGIN,
            ScopeInterface::SCOPE_STORE
        );

        return $margin > 0 ? $margin : 0.0;
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
                    'instructions' => $this->getInstructions(),
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

            $url = $url . rawurlencode($cryptoCurrency) . '/payment_request';

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
