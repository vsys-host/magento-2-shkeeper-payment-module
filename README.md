# magento-2-shkeeper-payment-module
SHKeeper payment module for for Magento 2 Community Edition

## Requirements

* Magento 2 Community Edition 2.x (Tested on 2.4.7)
* PHP version >= 8.0.0

*Note:* this module has been tested only with Magento 2 __Community Edition__, it may not work as intended with Magento 2 __Enterprise Edition__


## Installation (manual)

* Download the latest Payment Module archive from [Github releases page](https://github.com/vsys-host/magento-2-shkeeper-payment-module/releases), unpack it and upload its contents to a new folder ```<root>/app/code/``` of your Magento 2 installation


* Enable Payment Module

  ```sh
  $ php bin/magento module:enable Shkeeper_Gateway --clear-static-content
  ```

  ```sh
  $ php bin/magento setup:upgrade
  ```

  ```sh
  $ php bin/magento setup:di:compile
  ```

* Deploy Magento Static Content (__Execute If needed__)

  ```sh
  $ php bin/magento setup:static-content:deploy -f
  ```
  
  ```sh
  $ php bin/magento indexer:reindex
  ```   

  ```sh
  $ php bin/magento cache:flush
  ```

  

## Configuration

* Login inside the __Admin Panel__ and go to ```Stores``` -> ```Configuration``` -> ```Sales``` -> ```Payment Methods```
* If the Payment Module Panel ```SHKeeper``` is not visible in the list of available Payment Methods,
  go to  ```System``` -> ```Cache Management``` and clear Magento Cache by clicking on ```Flush Magento Cache```
* Set ```Enabled``` to ```Yes```, set the correct ```SHKeeper API Key``` and ```SHKeeper API URL```, configure additional settings and click ```Save config```

## Test data

You can use our demo SHKeeper installation to test module with your Magento 2. SHKeeper demo version working in a Testnet network, do not use it for real payments.
SHKeeper demo version is available from us, so you can try it yourself without installing it:

[SHKeeper demo](https://demo.shkeeper.io/)

**Login:** admin

**Password:** admin  


* SHKeeper API URL ```https://demo.shkeeper.io/api/v1/```
* ```SHKeeper API Key``` Actual should be taken from demo.shkeeper.io  ```Wallets``` -> ```Manage```. API Key can be taken from the any of available Wallets.
