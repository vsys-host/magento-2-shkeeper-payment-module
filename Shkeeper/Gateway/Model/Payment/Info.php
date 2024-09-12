<?php

namespace Shkeeper\Gateway\Model\Payment;

class Info extends \Magento\Payment\Model\Info
{

    protected $address;
    protected $amount;

    public function setAddress($address)
    {
        $this->address = $address;
        return $this;
    }

    public function setAmount($amount)
    {
        $this->amount = $amount;
        return $this;
    }

    public function getAmount()
    {
        return $this->amount;
    }

    public function getAddress()
    {
        return $this->address;
    }

}
