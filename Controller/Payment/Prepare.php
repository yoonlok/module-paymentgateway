<?php
/**
 * Copyright © Paydibs. All rights reserved.
 */
namespace Paydibs\PaymentGateway\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Checkout\Model\Session;
use Magento\Sales\Model\OrderFactory;
use Magento\Framework\Controller\Result\JsonFactory;
use Paydibs\PaymentGateway\Model\PaymentMethod;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;

class Prepare extends Action implements CsrfAwareActionInterface
{
    /**
     * @var Session
     */
    protected $checkoutSession;

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var PaymentMethod
     */
    protected $paymentMethod;
    
    /**
     * @var JsonFactory
     */
    protected $jsonFactory;
    
    /**
     * @var RemoteAddress
     */
    protected $remoteAddress;
    
    /**
     * @var LoggerInterface
     */
    protected $_logger;

    /**
     * @param Context $context
     * @param Session $checkoutSession
     * @param OrderFactory $orderFactory
     * @param PaymentMethod $paymentMethod
     * @param JsonFactory $jsonFactory
     * @param RemoteAddress $remoteAddress
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        Session $checkoutSession,
        OrderFactory $orderFactory,
        PaymentMethod $paymentMethod,
        JsonFactory $jsonFactory,
        RemoteAddress $remoteAddress,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->checkoutSession = $checkoutSession;
        $this->orderFactory = $orderFactory;
        $this->paymentMethod = $paymentMethod;
        $this->jsonFactory = $jsonFactory;
        $this->remoteAddress = $remoteAddress;
        $this->_logger = $logger;
    }
    
    /**
     * Create exception in case CSRF validation failed.
     * Return null to skip CSRF check.
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Perform custom request validation.
     * Return null if default validation is needed.
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): bool
    {
        return true;
    }

    /**
     * Create payment and redirect to Paydibs
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        try {                
            // Get order
            $order = $this->checkoutSession->getLastRealOrder();
            if (!$order) {
                $this->paymentMethod->log('Prepare: Order not found');
                return $this->jsonFactory->create()->setData([
                    'error' => true, 
                    'message' => __('Order not found.')
                ]);
            }
            
            $payment = $order->getPayment();
            if (!isset($payment) || empty($payment)) {
                $this->paymentMethod->log('Prepare: Order Payment is empty');
                return $this->jsonFactory->create()->setData([
                    'error' => true, 
                    'message' => __('Order Payment is empty.')
                ]);
            }
            
            $method = $order->getPayment()->getMethod();
            if ($method !== "paydibs_payment_gateway") {
                $this->paymentMethod->log('Prepare: Payment method not correct');
                return $this->jsonFactory->create()->setData([
                    'error' => true, 
                    'message' => __('Payment method not correct.')
                ]);
            }
            
            $this->paymentMethod->log('Prepare: Order #' . $order->getIncrementId() . ' loaded successfully');
            
            // Get payment gateway URL
            $apiUrl = $this->paymentMethod->getApiUrl();
            
            // Get customer IP address
            $customerIp = $this->remoteAddress->getRemoteAddress();
            
            // Get billing address
            $billingAddress = $order->getBillingAddress();

            $this->paymentMethod->log('Prepare: currency code: ' . $order->getOrderCurrencyCode());
            
            // Prepare parameters for the payment gateway
            $params = [
                'TxnType' => 'PAY',
                'MerchantID' => $this->paymentMethod->getMerchantId(),
                'MerchantPymtID' => $order->getIncrementId(),
                'MerchantOrdID' => $order->getIncrementId(),
                'MerchantOrdDesc' => 'Order #' . $order->getIncrementId(),
                'MerchantTxnAmt' => number_format($order->getGrandTotal(), 2, '.', ''),
                'MerchantCurrCode' => $order->getOrderCurrencyCode(),
                'MerchantRURL' => str_replace('&', ';', $this->_url->getUrl('paydibs/payment/response', ['_secure' => true])),
                'CustIP' => $customerIp,
                'CustName' => $billingAddress->getFirstname() . ' ' . $billingAddress->getLastname(),
                'CustEmail' => $billingAddress->getEmail() ?: $order->getCustomerEmail(),
                'CustPhone' => $billingAddress->getTelephone(),
                'MerchantCallbackURL' => str_replace('&', ';', $this->_url->getUrl('paydibs/payment/notify', ['_secure' => true])),
                'PageTimeout' => $this->paymentMethod->getPageTimeout()
            ];
            
            $params['Sign'] = $this->generateSignature($params);
            $query = http_build_query($params);
            $fullUrl = rtrim($apiUrl, '?') . '?' . $query;
            $this->checkoutSession->setNoOrderRedirect(true);
            
            if ($this->paymentMethod->isCartRestorationEnabled()) {
                $this->preventCartClearing($order);
                $this->paymentMethod->log('Prepare: Cart kept active for order #' . $order->getIncrementId() . ' as cart restoration is enabled');
            }

            return $this->jsonFactory->create()->setData(['paymentUrl' => $fullUrl]);
        } catch (\Exception $e) {
            $this->paymentMethod->log('Prepare: Error occurred: ' . $e->getMessage());
            return $this->jsonFactory->create()->setData([
                'error' => true, 
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Prevent the cart from being cleared after order creation
     * 
     * @param \Magento\Sales\Model\Order $order
     * @return void
     */
    protected function preventCartClearing($order)
    {
        try {
            $quoteId = $order->getQuoteId();
            if (!$quoteId) {
                $this->paymentMethod->log('Prepare: No quote ID found for order #' . $order->getIncrementId());
                return;
            }
            
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $quoteRepository = $objectManager->get('\Magento\Quote\Model\QuoteRepository');
            $quoteFactory = $objectManager->get('\Magento\Quote\Model\QuoteFactory');
            
            $quote = $quoteFactory->create()->loadByIdWithoutStore($quoteId);
            if (!$quote->getId()) {
                $this->paymentMethod->log('Prepare: Quote not found for ID: ' . $quoteId);
                return;
            }
            
            $quote->setIsActive(1);
            $quoteRepository->save($quote);
            $this->checkoutSession->replaceQuote($quote);
            
        } catch (\Exception $e) {
            $this->paymentMethod->log('Prepare: Error preventing cart clearing: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate signature for Paydibs request
     *
     * @param array $params
     * @return string
     */
    protected function generateSignature($params)
    {
        $merchantPassword = $this->paymentMethod->getMerchantPassword();
        $pageTimeout = isset($params['PageTimeout']) ? $params['PageTimeout'] : '300';
        $merchantCallbackURL = isset($params['MerchantCallbackURL']) ? $params['MerchantCallbackURL'] : '';
        $token = isset($params['Token']) ? $params['Token'] : '';
       
        $signatureString = $merchantPassword . 
                          $params['TxnType'] . 
                          $params['MerchantID'] . 
                          $params['MerchantPymtID'] . 
                          $params['MerchantOrdID'] . 
                          $params['MerchantRURL'] . 
                          $params['MerchantTxnAmt'] . 
                          $params['MerchantCurrCode'] . 
                          $params['CustIP'] . 
                          $pageTimeout . 
                          $merchantCallbackURL . 
                          $token;
        
        $signatureStringWithoutPassword = 
                          $params['TxnType'] . 
                          $params['MerchantID'] . 
                          $params['MerchantPymtID'] . 
                          $params['MerchantOrdID'] . 
                          $params['MerchantRURL'] . 
                          $params['MerchantTxnAmt'] . 
                          $params['MerchantCurrCode'] . 
                          $params['CustIP'] . 
                          $pageTimeout . 
                          $merchantCallbackURL . 
                          $token;
                          
        $this->paymentMethod->log('Prepare: Signature string (without password): ' . $signatureStringWithoutPassword);
        
        $signature = hash('sha512', $signatureString);
        
        return $signature;
    }
}
