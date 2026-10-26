define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'mps_payments',
        component: 'Mps_Payments/js/view/payment/method-renderer/mps-payments'
    });

    return Component.extend({});
});
