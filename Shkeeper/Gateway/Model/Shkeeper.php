<?php

namespace Shkeeper\Gateway\Model;

use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Framework\DataObject;

class Shkeeper extends AbstractMethod
{

    protected $_code = 'shkeeper';
    protected $_isOffline = true;

    public function assignData(DataObject $data)
    {
        parent::assignData($data);

        $additionalData = $data->getData('additional_data');

        if (empty($additionalData)) {
            return $this;
        }

        $this->getInfoInstance()->setAdditionalInformation('wallet', $additionalData['wallet']);
        $this->getInfoInstance()->setAdditionalInformation('amount', $additionalData['amount']);

        return $this;
    }

    /**
     * Get instructions text from config
     *
     * @return string
     */
    public function getInstructions()
    {
        $instructions = $this->getConfigData('instructions');
        return $instructions !== null ? trim($instructions) : '';
    }

}
