<?php
/**
 * Copyright Paydibs. All rights reserved.
 */
namespace Paydibs\PaymentGateway\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Checkout\Model\Session;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Paydibs\PaymentGateway\Model\PaymentMethod;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Psr\Log\LoggerInterface;

class Response extends Action implements CsrfAwareActionInterface
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
     * @var BuilderInterface
     */
    protected $transactionBuilder;
    
    /**
     * @var PaymentMethod
     */
    protected $paymentMethod;
    
    /**
     * @var LoggerInterface
     */
    protected $_logger;
    
    /**
     * @var CustomerSession
     */
    protected $customerSession;

    /**
     * @param Context $context
     * @param Session $checkoutSession
     * @param OrderFactory $orderFactory
     * @param BuilderInterface $transactionBuilder
     * @param PaymentMethod $paymentMethod
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        Session $checkoutSession,
        OrderFactory $orderFactory,
        BuilderInterface $transactionBuilder,
        PaymentMethod $paymentMethod,
        LoggerInterface $logger,
        CustomerSession $customerSession
    ) {
        parent::__construct($context);
        $this->checkoutSession = $checkoutSession;
        $this->orderFactory = $orderFactory;
        $this->transactionBuilder = $transactionBuilder;
        $this->paymentMethod = $paymentMethod;
        $this->_logger = $logger;
        $this->customerSession = $customerSession;
    }

    /**
     * Process payment response from Paydibs
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    /**
     * Create exception in case CSRF validation failed.
     * Return null to allow CsrfAwareActionInterface to automatically validate CSRF token
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Disable CSRF validation for payment gateway callbacks
     *
     * @param RequestInterface $request
     * @return bool
     */
    public function validateForCsrf(RequestInterface $request): bool
    {
        return true;
    }
    
    public function execute()
    {
        $this->paymentMethod->log('Response: Request received: ' . print_r($this->getRequest()->getParams(), true));
        $this->_logger->debug('Response: Request received: ' . print_r($this->getRequest()->getParams(), true));
        // Get response parameters
        $params = $this->getRequest()->getParams();
        
        // If this is a POST request, get POST data instead
        if ($this->getRequest()->isPost()) {
            $params = $this->getRequest()->getPostValue();
        }
        
        // Check if we have the required parameters
        if (!isset($params['MerchantPymtID']) || !isset($params['PTxnStatus']) || !isset($params['PTxnID'])) {
            $this->messageManager->addErrorMessage(__('Invalid payment response received.'));
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }
        
        // Load the order
        $order = $this->orderFactory->create()->loadByIncrementId($params['MerchantPymtID']);
        
        if (!$order->getId()) {
            $this->messageManager->addErrorMessage(__('Order not found.'));
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }
        
        // Verify the signature if provided
        if (isset($params['Sign']) && !$this->verifySignature($params)) {
            $this->messageManager->addErrorMessage(__('Invalid payment signature.'));
            return $this->resultRedirectFactory->create()->setPath('checkout/cart');
        }
        
        // Process the payment status
        $txnStatus = $params['PTxnStatus'];

        
        // Ensure customer session is preserved
        if ($order->getCustomerId() && !$this->customerSession->isLoggedIn()) {
            $this->customerSession->loginById($order->getCustomerId());
        }   
        
        // Status '0' means success
        if ($txnStatus === '0') {
            // Create transaction
            $payment = $order->getPayment();
            $payment->setTransactionId($params['PTxnID']);
            $payment->setLastTransId($params['PTxnID']);
            
            // Add transaction details
            $transaction = $this->transactionBuilder
                ->setPayment($payment)
                ->setOrder($order)
                ->setTransactionId($params['PTxnID'])
                ->setAdditionalInformation(
                    [Transaction::RAW_DETAILS => $params]
                )
                ->setFailSafe(true)
                ->build(Transaction::TYPE_CAPTURE);
            
            // Save payment and transaction
            $payment->addTransactionCommentsToOrder($transaction, __('Payment successfully processed by Paydibs.'));
            $payment->setParentTransactionId(null);
            
            // Update order status
            $order->setState(Order::STATE_PROCESSING)
                  ->setStatus(Order::STATE_PROCESSING)
                  ->addCommentToStatusHistory(
                      __('Payment successfully processed by Paydibs. Transaction ID: %1', $params['PTxnID']),
                      true
                  );
            
            // Create invoice
            if ($order->canInvoice()) {
                try {
                    // Get the invoice service
                    $invoiceService = $this->_objectManager->create(\Magento\Sales\Model\Service\InvoiceService::class);
                    
                    // Create the invoice
                    $invoice = $invoiceService->prepareInvoice($order);
                    
                    // Make sure the invoice is not empty
                    if (!$invoice) {
                        throw new \Magento\Framework\Exception\LocalizedException(
                            __('We can\'t create an invoice right now.')
                        );
                    }
                    
                    // Set invoice state to paid
                    $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                    $invoice->register();
                    
                    // Save the invoice
                    $transaction = $this->_objectManager->create(\Magento\Framework\DB\Transaction::class);
                    $transaction->addObject($invoice)
                                ->addObject($invoice->getOrder())
                                ->save();
                    
                    // Add comment to order history
                    $order->addCommentToStatusHistory(
                        __('Invoice #%1 created.', $invoice->getIncrementId()),
                        false
                    );
                    
                    // Send invoice email
                    $this->_objectManager->create(\Magento\Sales\Model\Order\Email\Sender\InvoiceSender::class)
                         ->send($invoice);
                    
                } catch (\Exception $e) {
                    $this->paymentMethod->log('Response: Error creating invoice: ' . $e->getMessage());
                }
            }
            
            // Save order and payment
            $payment->save();
            $order->save();
            
            // Set required session variables for success page
            $this->checkoutSession->setLastQuoteId($order->getQuoteId());
            $this->checkoutSession->setLastSuccessQuoteId($order->getQuoteId());
            $this->checkoutSession->setLastOrderId($order->getId());
            $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
            $this->checkoutSession->setLastOrderStatus($order->getStatus());
            
            $this->messageManager->addSuccessMessage(__('Your payment was successful.'));
            return $this->resultRedirectFactory->create()->setPath('checkout/onepage/success');
        } else {
            // Payment failed or other status
            $errorMessage = isset($params['PTxnMsg']) ? $params['PTxnMsg'] : 'Payment failed';
            $txnStatus = $params['PTxnStatus'];
            
            // Handle different transaction statuses
            switch ($txnStatus) {
                case '0': // Payment successful - already handled above
                    break;
                    
                case '2': // Payment pending - do nothing, keep order as is
                    $order->addCommentToStatusHistory(
                        __('Payment pending at Paydibs. Transaction ID: %1', $params['PTxnID']),
                        Order::STATE_PENDING_PAYMENT
                    )->save();
                    $this->messageManager->addNoticeMessage(__('Your payment is being processed. We will notify you when it completes.'));
                    return $this->resultRedirectFactory->create()->setPath('checkout/onepage/success');
                    
                case '1': // Payment failed
                case '9': // Payment voided
                case '17': // Payment cancelled at payment page
                case '-1': // Transaction not found
                case '-2': // Internal system error
                default: // Any other status - cancel order
                    try {
                        // First register all items as canceled
                        foreach ($order->getAllItems() as $item) {
                            $item->cancel();
                        }
                        
                        // Force the order state and status to canceled
                        $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED)
                            ->setStatus(\Magento\Sales\Model\Order::STATE_CANCELED)
                            ->addCommentToStatusHistory(
                                __('Payment failed at Paydibs123. Status: %1, Error: %2', $txnStatus, $errorMessage),
                                false
                            );
                        
                        $order->save();
                        $this->paymentMethod->log('Response: Payment failed for order ' . $params['MerchantPymtID'] . '. Status: ' . $txnStatus . ', Error: ' . $errorMessage);
                    } catch (\Exception $e) {
                        $this->paymentMethod->log('Response: Error canceling order: ' . $e->getMessage());
                    }
                    
                    $this->messageManager->addErrorMessage(__('Payment failed: %1', $errorMessage));
                    return $this->resultRedirectFactory->create()->setPath('checkout/cart');
            }
        }
    }
    
    /**
     * Verify signature from Paydibs response
     *
     * @param array $params
     * @return bool
     */
    protected function verifySignature($params)
    {
        $merchantPassword = $this->paymentMethod->getMerchantPassword();
        $signatureString = $merchantPassword . 
                          $params['MerchantID'] . 
                          $params['MerchantPymtID'] . 
                          $params['PTxnID'] . 
                          $params['MerchantOrdID'] . 
                          $params['MerchantTxnAmt'] . 
                          $params['MerchantCurrCode'] . 
                          $params['PTxnStatus'] . 
                          $params['AuthCode'];
        
        $merchantOrdID = isset($params['MerchantOrdID']) && !empty($params['MerchantOrdID']) 
            ? $params['MerchantOrdID'] 
            : $params['MerchantPymtID'];
            
        $authCode = isset($params['AuthCode']) ? $params['AuthCode'] : '';
        
        $signatureString = $merchantPassword . 
                          $params['MerchantID'] . 
                          $params['MerchantPymtID'] . 
                          $params['PTxnID'] . 
                          $merchantOrdID . 
                          $params['MerchantTxnAmt'] . 
                          $params['MerchantCurrCode'] . 
                          $params['PTxnStatus'] . 
                          $authCode;
        
        $signatureStringWithoutPassword = 
                          $params['MerchantID'] . 
                          $params['MerchantPymtID'] . 
                          $params['PTxnID'] . 
                          $merchantOrdID . 
                          $params['MerchantTxnAmt'] . 
                          $params['MerchantCurrCode'] . 
                          $params['PTxnStatus'] . 
                          $authCode;
                          
        $this->paymentMethod->log('Response: Signature string (without password): ' . $signatureStringWithoutPassword);
        
        // Generate SHA512 hash
        $calculatedSignature = hash('sha512', $signatureString);
        
        // Compare with received signature
        $result = hash_equals($calculatedSignature, $params['Sign']);
        
        if (!$result) {
            $this->paymentMethod->log('Response: Signature verification failed.');
            $this->paymentMethod->log('Response: Expected signature: ' . $calculatedSignature);
            $this->paymentMethod->log('Response: Received signature: ' . $params['Sign']);
            $this->paymentMethod->log('Response: Signature length - Expected: ' . strlen($calculatedSignature) . ', Received: ' . strlen($params['Sign']));
        } else {
            $this->paymentMethod->log('Response: Signature verification successful');
        }
        
        return $result;
    }
}
