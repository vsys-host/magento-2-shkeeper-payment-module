/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
/*browser:true*/
/*global define*/
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'shkeeper',
                component: 'Shkeeper_Gateway/js/view/payment/method-renderer/shkeeper-method'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);
