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
                type: 'paydibs_payment_gateway',
                component: 'Paydibs_PaymentGateway/js/view/payment/method-renderer/paydibs-method'
            }
        );
        return Component.extend({});
    }
);
