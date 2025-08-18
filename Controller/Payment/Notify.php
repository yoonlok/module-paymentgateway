<?php
/**
 * Copyright © Paydibs. All rights reserved.
 */
namespace Paydibs\PaymentGateway\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Paydibs\PaymentGateway\Model\PaymentMethod;
use Psr\Log\LoggerInterface;

class Notify extends Action implements CsrfAwareActionInterface
{
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
     * @param Context $context
     * @param OrderFactory $orderFactory
     * @param BuilderInterface $transactionBuilder
     * @param PaymentMethod $paymentMethod
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        OrderFactory $orderFactory,
        BuilderInterface $transactionBuilder,
        PaymentMethod $paymentMethod,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->orderFactory = $orderFactory;
        $this->transactionBuilder = $transactionBuilder;
        $this->paymentMethod = $paymentMethod;
        $this->_logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Process server-to-server notification
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $result = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_RAW);
        $result->setHeader('Content-Type', 'text/plain');
        
        $this->paymentMethod->log('Notify: Request received: ' . json_encode($this->getRequest()->getParams()));
        
        try {
            $params = $this->getRequest()->getPostValue();
            if (!isset($params['MerchantPymtID']) || !isset($params['PTxnStatus'])) {
                $this->paymentMethod->log('Notify: Notification missing required parameters');
                $result->setContents('ERROR: Missing required parameters');
                return $result;
            }
            $order = $this->orderFactory->create()->loadByIncrementId($params['MerchantPymtID']);
            if (!$order->getId()) {
                $this->paymentMethod->log('Notify: Order not found: ' . $params['MerchantPymtID']);
                $result->setContents('ERROR: Order not found');
                return $result;
            }
            if (isset($params['Sign']) && !$this->verifySignature($params)) {
                $this->paymentMethod->log('Notify: Invalid signature for order ' . $params['MerchantPymtID']);
                $result->setContents('ERROR: Invalid signature');
                return $result;
            }
            $txnStatus = $params['PTxnStatus'];
            if ($txnStatus === '0') {
                if ($order->getState() === Order::STATE_PROCESSING) {
                    $this->paymentMethod->log('Notify: Order already processed: ' . $params['MerchantPymtID']);
                    $result->setContents('OK: Order already processed');
                    return $result;
                }
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
                $payment->setParentTransactionId(null);
                
                // Update order status
                $order->setState(Order::STATE_PROCESSING)
                      ->setStatus(Order::STATE_PROCESSING)
                      ->addCommentToStatusHistory(
                          __('Payment successfully processed by Paydibs. Transaction 123 ID: %1', $params['PTxnID']),
                          false
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
                        
                        $this->paymentMethod->log('Notify: Invoice created for order ' . $params['MerchantPymtID']);
                        
                    } catch (\Exception $e) {
                        $this->paymentMethod->log('Notify: Error creating invoice: ' . $e->getMessage());
                    }
                }
                
                // Save order and payment
                $payment->save();
                $order->save();
                
                $this->paymentMethod->log('Notify: Payment successful for order ' . $params['MerchantPymtID']);
                $result->setContents('OK');
                return $result;
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
                        $this->paymentMethod->log('Notify: Payment pending for order ' . $params['MerchantPymtID']);
                        $result->setContents('OK');
                        return $result;
                        
                    case '1': // Payment failed
                    case '9': // Payment voided
                    case '17': // Payment cancelled at payment page
                    case '-1': // Transaction not found
                    case '-2': // Internal system error
                    default: // Any other status - cancel order
                        try {
                            foreach ($order->getAllItems() as $item) {
                                $item->cancel();
                            }
                            $order->setState(\Magento\Sales\Model\Order::STATE_CANCELED)
                                ->setStatus(\Magento\Sales\Model\Order::STATE_CANCELED)
                                ->addCommentToStatusHistory(
                                    __('Payment failed at Paydibs. Status: %1, Error: %2', $txnStatus, $errorMessage),
                                    false
                                );
                                
                            $order->save();
                            $this->paymentMethod->log('Notify: Payment failed for order ' . $params['MerchantPymtID'] . '. Status: ' . $txnStatus . ', Error: ' . $errorMessage);
                        } catch (\Exception $e) {
                            $this->paymentMethod->log('Notify: Error canceling order: ' . $e->getMessage());
                        }
                        
                        $result->setContents('OK');
                        return $result;
                }
            }
        } catch (\Exception $e) {
            $this->paymentMethod->log('Notify: Notification error: ' . $e->getMessage());
            $result->setContents('ERROR: ' . $e->getMessage());
            return $result;
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
                          (isset($params['MerchantOrdID']) ? $params['MerchantOrdID'] : $params['MerchantPymtID']) . 
                          $params['MerchantTxnAmt'] . 
                          $params['MerchantCurrCode'] . 
                          $params['PTxnStatus'] . 
                          (isset($params['AuthCode']) ? $params['AuthCode'] : '');
        
        $signatureStringWithoutPassword = 
                          $params['MerchantID'] . 
                          $params['MerchantPymtID'] . 
                          $params['PTxnID'] . 
                          (isset($params['MerchantOrdID']) ? $params['MerchantOrdID'] : $params['MerchantPymtID']) . 
                          $params['MerchantTxnAmt'] . 
                          $params['MerchantCurrCode'] . 
                          $params['PTxnStatus'] . 
                          (isset($params['AuthCode']) ? $params['AuthCode'] : '');
                          
        $this->paymentMethod->log('Notify: Signature string (without password): ' . $signatureStringWithoutPassword);
        
        $calculatedSignature = hash('sha512', $signatureString);
        
        $result = hash_equals($calculatedSignature, $params['Sign']);
        
        if (!$result) {
            $this->paymentMethod->log('Notify: Signature verification failed.');
            $this->paymentMethod->log('Notify: Expected signature: ' . $calculatedSignature);
            $this->paymentMethod->log('Notify: Received signature: ' . $params['Sign']);
            $this->paymentMethod->log('Notify: Signature length - Expected: ' . strlen($calculatedSignature) . ', Received: ' . strlen($params['Sign']));
        } else {
            $this->paymentMethod->log('Notify: Signature verification successful');
        }
        
        return $result;
    }
}
