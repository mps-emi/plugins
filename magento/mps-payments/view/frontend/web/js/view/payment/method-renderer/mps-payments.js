/**
 * MPS at checkout: place the order, then hand the browser to the module's redirect, which opens the
 * payment and sends the shopper on to the MPS-hosted checkout. No card details are asked for here.
 */
define([
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/full-screen-loader'
], function (Component, fullScreenLoader) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Mps_Payments/payment/mps-payments'
        },

        redirectAfterPlaceOrder: false,

        getInstructions: function () {
            return window.checkoutConfig.payment.mps_payments.instructions;
        },

        afterPlaceOrder: function () {
            fullScreenLoader.startLoader();
            // replace(): the browser's back button returns to the shop, not to a redirect that already ran.
            window.location.replace(window.checkoutConfig.payment.mps_payments.redirectUrl);
        }
    });
});
