<?php
/**
 * Paydibs Payment Gateway
 *
 * Manual trigger for query pending orders
 */
namespace Paydibs\PaymentGateway\Controller\Payment;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Paydibs\PaymentGateway\Cron\QueryPendingOrders;
use Psr\Log\LoggerInterface;
use Paydibs\PaymentGateway\Model\PaymentMethod;

class Requery implements HttpGetActionInterface
{
    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var QueryPendingOrders
     */
    protected $queryPendingOrders;

    /**
     * @var LoggerInterface
     */
    protected $logger;
    
    /**
     * @var PaymentMethod
     */
    protected $paymentMethod;

    /**
     * Constructor
     *
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     * @param QueryPendingOrders $queryPendingOrders
     * @param LoggerInterface $logger
     * @param PaymentMethod $paymentMethod
     */
    public function __construct(
        JsonFactory $resultJsonFactory,
        QueryPendingOrders $queryPendingOrders,
        LoggerInterface $logger,
        PaymentMethod $paymentMethod
    ) {
        $this->resultJsonFactory = $resultJsonFactory;
        $this->queryPendingOrders = $queryPendingOrders;
        $this->logger = $logger;
        $this->paymentMethod = $paymentMethod;
    }

    /**
     * Execute action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $resultJson = $this->resultJsonFactory->create();
        $response = ['success' => false, 'message' => ''];

        try {
            $this->paymentMethod->log('Requery: Starting manual query of pending orders');
            $this->queryPendingOrders->execute();
            $response = [
                'success' => true,
                'message' => 'Successfully queried pending Paydibs orders'
            ];
            $this->paymentMethod->log('Requery: Manual query completed successfully');
        } catch (\Exception $e) {
            $this->paymentMethod->log('Requery: Error: ' . $e->getMessage());
            $response = [
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ];
        }

        return $resultJson->setData($response);
    }
}
