define([
    'jquery',
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/full-screen-loader',
    'mage/url',
    'Magento_Checkout/js/model/payment/additional-validators',
    'Magento_Ui/js/model/messageList'
], function ($, Component, fullScreenLoader, mageUrl, additionalValidators, globalMessageList) {
    'use strict';
    return Component.extend({
        defaults: {
            template: 'Paydibs_PaymentGateway/payment/paydibs',
            redirectAfterPlaceOrder: false
        },

        /**
         * Get payment method data
         */
        getData: function () {
            return {
                'method': this.item.method,
                'additional_data': {}
            };
        },

        /**
         * After place order callback
         */
        afterPlaceOrder: function () {
            fullScreenLoader.startLoader();
            $.ajax({
                url: mageUrl.build("paydibs/payment/prepare"),
                type: 'GET',
                dataType: 'json',
                success: function (response) {
                    if (response && response.paymentUrl) {
                        window.location.href = response.paymentUrl;
                    } else if (response && response.error) {
                        console.error('Paydibs payment error:', response.message);
                        globalMessageList.addErrorMessage({
                            message: response.message || 'An error occurred during payment processing.'
                        });
                        fullScreenLoader.stopLoader();
                    } else {
                        console.error('Paydibs payment error: Invalid response format');
                        globalMessageList.addErrorMessage({
                            message: 'An error occurred during payment processing.'
                        });
                        fullScreenLoader.stopLoader();
                    }
                },
                error: function (xhr, status, error) {
                    console.error('Paydibs payment AJAX error:', error);
                    globalMessageList.addErrorMessage({
                        message: 'An error occurred during payment processing. Please try again.'
                    });
                    fullScreenLoader.stopLoader();
                }
            });
        }
    });
});
