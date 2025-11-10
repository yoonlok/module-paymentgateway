<?php
/**
 * Copyright © Paydibs. All rights reserved.
 */
namespace Paydibs\PaymentGateway\Block;

use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Model\Config;
use Magento\Framework\Registry;

class Info extends \Magento\Payment\Block\Info
{
    /**
     * @var Registry
     */
    protected $registry;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->registry = $registry;
    }

    /**
     * Prepare payment information
     *
     * @param \Magento\Framework\DataObject|array|null $transport
     * @return \Magento\Framework\DataObject
     */
    protected function _prepareSpecificInformation($transport = null)
    {
        $transport = parent::_prepareSpecificInformation($transport);
        $payment = $this->getInfo();
        
        $methodTitle = $this->getMethod()->getConfigData('title', $payment->getOrder()->getStoreId());
        if ($methodTitle) {
            $transport->addData([
                'Method Title' => $methodTitle
            ]);
        }
        
        $transactionId = $payment->getLastTransId();
        if ($transactionId) {
            $transport->addData([
                'Paydibs Transaction ID' => $transactionId
            ]);
        }
        
        $additionalInfo = $payment->getAdditionalInformation();
        
        if (isset($additionalInfo['MerchantTxnAmt'])) {
            $amount = str_replace('_', '.', $additionalInfo['MerchantTxnAmt']);
            $transport->addData([
                'Paydibs Amount' => $amount
            ]);
        }
        
        if (isset($additionalInfo['MerchantCurrCode'])) {
            $transport->addData([
                'Paydibs Currency Code' => $additionalInfo['MerchantCurrCode']
            ]);
        }
        
        return $transport;
    }
}
