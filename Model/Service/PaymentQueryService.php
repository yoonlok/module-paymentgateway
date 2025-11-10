<?php
/**
 * Paydibs Payment Gateway
 *
 * @category    Paydibs
 * @package     Paydibs_PaymentGateway
 */
namespace Paydibs\PaymentGateway\Model\Service;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Paydibs\PaymentGateway\Model\PaymentMethod;
use Magento\Quote\Model\QuoteFactory;
use Magento\Checkout\Model\Session;
use Psr\Log\LoggerInterface;

class PaymentQueryService
{
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var Json
     */
    protected $json;

    /**
     * @var PaymentMethod
     */
    protected $paymentMethod;

    /**
     * @var QuoteFactory
     */
    protected $quoteFactory;

    /**
     * @var Session
     */
    protected $checkoutSession;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var TransactionRepositoryInterface
     */
    protected $transactionRepository;

    /**
     * @var TransactionFactory
     */
    protected $transactionFactory;

    /**
     * @var InvoiceService
     */
    protected $invoiceService;

    /**
     * @var InvoiceSender
     */
    protected $invoiceSender;

    /**
     * @var OrderSender
     */
    protected $orderSender;

    /**
     * @param Curl $curl
     * @param Json $json
     * @param PaymentMethod $paymentMethod
     * @param LoggerInterface $logger
     * @param OrderRepositoryInterface $orderRepository
     * @param TransactionRepositoryInterface $transactionRepository
     * @param TransactionFactory $transactionFactory
     * @param InvoiceService $invoiceService
     * @param InvoiceSender $invoiceSender
     * @param OrderSender $orderSender
     */
    public function __construct(
        Curl $curl,
        Json $json,
        PaymentMethod $paymentMethod,
        QuoteFactory $quoteFactory,
        Session $checkoutSession,
        LoggerInterface $logger,
        OrderRepositoryInterface $orderRepository,
        TransactionRepositoryInterface $transactionRepository,
        TransactionFactory $transactionFactory,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        OrderSender $orderSender
    ) {
        $this->curl = $curl;
        $this->json = $json;
        $this->paymentMethod = $paymentMethod;
        $this->quoteFactory = $quoteFactory;
        $this->checkoutSession = $checkoutSession;
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
        $this->transactionRepository = $transactionRepository;
        $this->transactionFactory = $transactionFactory;
        $this->invoiceService = $invoiceService;
        $this->invoiceSender = $invoiceSender;
        $this->orderSender = $orderSender;
    }

    /**
     * Generate signature for query request
     *
     * @param array $params
     * @return string
     */
    protected function generateQueryRequestSignature($params)
    {
        $merchantPassword = $this->paymentMethod->getMerchantPassword();
        $signatureString = $merchantPassword .
            $params['MerchantID'] .
            $params['MerchantPymtID'] .
            $params['MerchantTxnAmt'] .
            $params['MerchantCurrCode'];
            
        $this->paymentMethod->log('Query: Request signature string (without password): ' . 
            $params['MerchantID'] .
            $params['MerchantPymtID'] .
            $params['MerchantTxnAmt'] .
            $params['MerchantCurrCode']);
            
        // Generate SHA512 hash
        $signature = hash('sha512', $signatureString);
        
        return $signature;
    }

    /**
     * Clear the cart by deactivating the quote
     * Only called when payment is successful
     *
     * @param int $quoteId
     * @return bool
     */
    protected function clearCart($quoteId)
    {
        try {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $quoteRepository = $objectManager->get('\Magento\Quote\Model\QuoteRepository');
            
            $quote = $this->quoteFactory->create()->loadByIdWithoutStore($quoteId);
            if (!$quote->getId()) {
                $this->paymentMethod->log('Response: Failed to clear cart - Quote not found for ID: ' . $quoteId);
                return false;
            }
            
            $quote->setIsActive(false);
            $quoteRepository->save($quote);
            
            $this->paymentMethod->log('Response: Cart cleared for quote ID: ' . $quoteId);
            return true;
        } catch (\Exception $e) {
            $this->paymentMethod->log('Response: Error clearing cart: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Restore quote 
     *
     * @param int $quoteId
     * @return bool
     */
    protected function restoreQuote($quoteId)
    {
        try {
            $quote = $this->quoteFactory->create()->loadByIdWithoutStore($quoteId);
            if (!$quote->getId()) {
                $this->paymentMethod->log('Response: Failed to restore quote - Quote not found for ID: ' . $quoteId);
                return false;
            }
            
            $quote->setIsActive(true)->setReservedOrderId(null)->save();
            $this->checkoutSession->replaceQuote($quote);
            $this->paymentMethod->log('Response: Quote successfully restored for ID: ' . $quoteId);
            return true;
        } catch (\Exception $e) {
            $this->paymentMethod->log('Response: Error restoring quote: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verify signature for query response
     *
     * @param array $response
     * @return bool
     */
    protected function verifyQueryResponseSignature($response)
    {
        if (!isset($response['Sign'])) {
            $this->paymentMethod->log('Query: Missing signature in response');
            return false;
        }
        
        $merchantPassword = $this->paymentMethod->getMerchantPassword();
        $signatureString = $merchantPassword;
        $fields = [
            'MerchantID',
            'MerchantPymtID',
            'PTxnID',
            'MerchantOrdID',
            'MerchantTxnAmt',
            'MerchantCurrCode',
            'PTxnStatus',
            'AuthCode'
        ];
        
        foreach ($fields as $field) {
            $signatureString .= isset($response[$field]) ? $response[$field] : '';
        }
        
        $this->paymentMethod->log('Query: Response signature string (without password): ' . 
            (isset($response['MerchantID']) ? $response['MerchantID'] : '') .
            (isset($response['MerchantPymtID']) ? $response['MerchantPymtID'] : '') .
            (isset($response['PTxnID']) ? $response['PTxnID'] : '') .
            (isset($response['MerchantOrdID']) ? $response['MerchantOrdID'] : '') .
            (isset($response['MerchantTxnAmt']) ? $response['MerchantTxnAmt'] : '') .
            (isset($response['MerchantCurrCode']) ? $response['MerchantCurrCode'] : '') .
            (isset($response['PTxnStatus']) ? $response['PTxnStatus'] : '') .
            (isset($response['AuthCode']) ? $response['AuthCode'] : ''));
        
        $expectedSignature = hash('sha512', $signatureString);
        $actualSignature = $response['Sign'];
        
        return $expectedSignature === $actualSignature;
    }

    /**
     * Query payment status from Paydibs
     *
     * @param OrderInterface $order
     * @return array Response from Paydibs
     */
    public function queryPaymentStatus(OrderInterface $order)
    {
        $this->paymentMethod->log('Query: Querying payment status for order #' . $order->getIncrementId());
        $apiUrl = $this->paymentMethod->getApiUrl();
        $params = [
            'TxnType' => 'QUERY',
            'MerchantID' => $this->paymentMethod->getMerchantId(),
            'MerchantPymtID' => $order->getIncrementId(),
            'MerchantTxnAmt' => number_format($order->getGrandTotal(), 2, '.', ''),
            'MerchantCurrCode' => $order->getOrderCurrencyCode()
        ];
        
        $params['Sign'] = $this->generateQueryRequestSignature($params);
        
        $queryUrl = $apiUrl . '?' . http_build_query($params);
        
        $this->paymentMethod->log('Query: Query URL: ' . $queryUrl);
        
        try {
            $this->curl->get($queryUrl);
            $response = $this->curl->getBody();
            $responseParams = $this->json->unserialize($response);
            $this->paymentMethod->log('Query: Parsed response: ' . print_r($responseParams, true));
            
            return $responseParams;
        } catch (\Exception $e) {
            $this->paymentMethod->log('Query: Error querying payment status: ' . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Process query response and update order status
     *
     * @param OrderInterface $order
     * @param array $response
     * @return bool
     */
    public function processQueryResponse(OrderInterface $order, array $response)
    {
        if (isset($response['error'])) {
            $this->paymentMethod->log('Query: Error in response for order #' . $order->getIncrementId() . ': ' . $response['error']);
            return false;
        }
        
        if (isset($response['Sign'])) {
            if (!$this->verifyQueryResponseSignature($response)) {
                $this->paymentMethod->log('Query: Invalid signature in response for order #' . $order->getIncrementId());
                return false;
            }
            $this->paymentMethod->log('Query: Signature verification successful for order #' . $order->getIncrementId());
        }
        
        if (!isset($response['PTxnStatus'])) {
            $this->paymentMethod->log('Query: Missing PTxnStatus in response for order #' . $order->getIncrementId());
            return false;
        }
        
        $txnStatus = $response['PTxnStatus'];
        $errorMessage = isset($response['PTxnMsg']) ? $response['PTxnMsg'] : 'No message';
        $txnId = isset($response['PTxnID']) ? $response['PTxnID'] : '';
        
        switch ($txnStatus) {
            case '0': // Payment successful
                if ($order->getState() == Order::STATE_PROCESSING) {
                    $this->paymentMethod->log('Query: Order #' . $order->getIncrementId() . ' already processed');
                    return true;
                }
                
                $payment = $order->getPayment();
                $payment->setTransactionId($txnId);
                $payment->setLastTransId($txnId);
                $paymentInfo = [
                    'PTxnID' => $txnId,
                    'MerchantPymtID' => $response['MerchantPymtID'],
                    'MerchantTxnAmt' => $response['MerchantTxnAmt'],
                    'MerchantCurrCode' => $response['MerchantCurrCode']
                ];
                
                if (isset($response['AuthCode'])) {
                    $paymentInfo['AuthCode'] = $response['AuthCode'];
                }
                
                if (isset($response['PTxnMsg'])) {
                    $paymentInfo['PTxnMsg'] = $response['PTxnMsg'];
                }
                
                foreach ($paymentInfo as $key => $value) {
                    $payment->setAdditionalInformation($key, $value);
                }
                
                $transaction = $payment->addTransaction(Transaction::TYPE_CAPTURE, null, true);
                $transaction->setAdditionalInformation(Transaction::RAW_DETAILS, $response);
                $transaction->setIsClosed(1);
                $transaction->save();
                
                $order->setState(Order::STATE_PROCESSING)
                    ->setStatus(Order::STATE_PROCESSING)
                    ->addCommentToStatusHistory(
                        __('Payment successful. Transaction ID: %1', $txnId),
                        true
                    );
                
                if (!$order->hasInvoices()) {
                    $invoice = $this->invoiceService->prepareInvoice($order);
                    $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                    $invoice->register();
                    
                    $transaction = $this->transactionFactory->create();
                    $transaction->addObject($invoice)->addObject($order)->save();
                    
                    $this->invoiceSender->send($invoice);
                }
                
                if (!$order->getEmailSent()) {
                    $this->orderSender->send($order);
                    $order->setEmailSent(true);
                }
                
                $order->save();
                $this->paymentMethod->log('Query: Order #' . $order->getIncrementId() . ' updated to processing');

                $this->clearCart($order->getQuoteId());
                return true;
                
            case '1': // Payment failed
            case '9': // Payment voided
            case '17': // Payment cancelled at payment page
            case '-1': // Transaction not found
            case '-2': // Internal system error
                if ($order->getState() == Order::STATE_CANCELED) {
                    $this->paymentMethod->log('Query: Order #' . $order->getIncrementId() . ' already canceled');
                    if ($this->paymentMethod->isCartRestorationEnabled()) {
                        $this->restoreQuote($order->getQuoteId());
                        $this->paymentMethod->log('Query: Cart restored for order #' . $order->getIncrementId());
                    } else {
                        $this->paymentMethod->log('Query: Cart restoration disabled for order #' . $order->getIncrementId());
                    }
                    return true;
                }
                
                try {
                    foreach ($order->getAllItems() as $item) {
                        $item->cancel();
                    }
                    
                    $order->setState(Order::STATE_CANCELED)
                        ->setStatus(Order::STATE_CANCELED)
                        ->addCommentToStatusHistory(
                            __('Payment failed at Paydibs. Status: %1, Error: %2', $txnStatus, $errorMessage),
                            false
                        );
                    
                    $order->save();
                    $this->paymentMethod->log('Query: Order #' . $order->getIncrementId() . ' canceled');
                    
                    if ($this->paymentMethod->isCartRestorationEnabled()) {
                        $this->restoreQuote($order->getQuoteId());
                        $this->paymentMethod->log('Query: Cart restored for order #' . $order->getIncrementId());
                    } else {
                        $this->paymentMethod->log('Query: Cart restoration disabled for order #' . $order->getIncrementId());
                    }
                    return true;
                } catch (\Exception $e) {
                    $this->paymentMethod->log('Query: Error canceling order #' . $order->getIncrementId() . ': ' . $e->getMessage());
                    return false;
                }
                
            case '2': // Payment pending
                $this->paymentMethod->log('Query: Order #' . $order->getIncrementId() . ' still pending');
                $order->addCommentToStatusHistory(
                    __('Payment still pending at Paydibs. Transaction ID: %1', $txnId),
                    Order::STATE_PENDING_PAYMENT
                )->save();
                return true;
                
            default:
                $this->paymentMethod->log('Query: Unknown status ' . $txnStatus . ' for order #' . $order->getIncrementId());
                return false;
        }
    }
}
