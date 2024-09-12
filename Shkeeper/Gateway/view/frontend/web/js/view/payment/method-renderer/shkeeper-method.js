define(
    [
        'jquery',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/action/select-payment-method',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/checkout-data',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/totals',
        'Magento_Checkout/js/model/payment/additional-validators',
        'mage/url',
        'ko',
    ],
    function (
        $,
        Component,
        placeOrderAction,
        selectPaymentMethodAction,
        customer,
        checkoutData,
        quote,
        totals,
        additionalValidators,
        url,
        ko
    ) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'Shkeeper_Gateway/payment/shkeeper',
                code: 'shkeeper',
                additionalData: ko.observable({}),
            },
            getCode: function() {
                return this.code;
            },
            isActive: function () {
                return true;
            },
            initialize: function () {
                this._super();
                this.shkeeperCode();
            },
            shkeeperCode: function() {
                let self = this;

                $.ajax({
                    url: url.build('shkeeper'),
                    type: 'POST',
                    success: function (response) {
                        let html = '<option>Select a Currency</option>';
                        Object.entries(response.crypto_list).forEach(data => {
                            let value = data[1];
                            html += '<option value="' + value.name + '">' + value.display_name + '</option>';
                        });
                        $('#currencies').html(html);
                    }
                });

                $('#currencies').on('change', function () {

                    // check if no currency selected
                    let currency = $(this).val();
                    if (currency.includes('Select')) { return }

                    $('#shkeeper-qrcode').html('');
                    $('#address-info').remove();
                    $('#amount-info').remove();

                    $.ajax({
                        url: url.build('shkeeper/invoice'),
                        type: 'POST',
                        data: {
                            crypto: currency,
                        },
                        success: function (response) {

                            $('#sh-address').append('<span id="address-info">' + response.wallet + '</span>');
                            $('#sh-amount').append('<span id="amount-info">' + response.amount + ' ' + response.display_name + '</span>');

                            new QRCode(document.getElementById("shkeeper-qrcode"), {
                                text: response.wallet + '?amount=' + response.amount,
                                width: 128,
                                height: 128,
                                colorDark: "#000000",
                                colorLight: "#ffffff",
                                correctLevel: QRCode.CorrectLevel.H
                            });

                            $('#shkeeper-address').val(response.wallet);
                            $('#shkeeper-amount').val(response.amount);

                            self.additionalData({
                               wallet: response.wallet,
                               amount: response.amount + ' ' + response.display_name,
                            });

                            $('#wallet-info').show();

                        }
                    });
                });
            },
            selectPaymentMethod: function() {
                selectPaymentMethodAction({
                    'method': this.getCode(),
                    'additional_data': this.additionalData(),
                });
                checkoutData.setSelectedPaymentMethod(this.getData());
                return true;
            },
            validate: function() {
                let wallet = $('#shkeeper-address').val();
                let amount = $('#shkeeper-amount').val();

                if (!wallet) {
                    alert('Wallet is not set. Please select a cryptocurrency.');
                    return false;
                }
                if (!amount) {
                    alert('Amount is not set. Please try again.');
                    return false;
                }
                return true;
            },
            getData: function() {

                return {
                    'method': this.getCode(),
                    'additional_data': this.additionalData(),
                };
            },
            placeOrder: function(data, event) {
                if (this.validate()) {
                    return this._super(data, event);
                }
                return false;
            }
        });
    }
);
