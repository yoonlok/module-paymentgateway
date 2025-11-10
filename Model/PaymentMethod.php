<?php
/**
 * Copyright Paydibs. All rights reserved.
 */
namespace Paydibs\PaymentGateway\Model;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Payment\Helper\Data;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;

/**
 * Paydibs Payment Gateway payment method model
 *
 * @method \Magento\Quote\Api\Data\PaymentMethodExtensionInterface getExtensionAttributes()
 */
class PaymentMethod extends AbstractMethod
{
    public const PAYMENT_METHOD_PAYDIBS_CODE = 'paydibs_payment_gateway';
    public const LOG_FILE = 'paydibs.log';

    /**
     * Payment method code
     *
     * @var string
     */
    protected $_code = self::PAYMENT_METHOD_PAYDIBS_CODE;

    /**
     * @var string
     */
    protected $_formBlockType = \Magento\Payment\Block\Form::class;

    /**
     * @var string
     */
    protected $_infoBlockType = \Paydibs\PaymentGateway\Block\Info::class;

    /**
     * Payment Method feature
     *
     * @var bool
     */
    protected $_isGateway = false;

    /**
     * Payment Method feature
     *
     * @var bool
     */
    protected $_canOrder = true;

    /**
     * Payment Method feature
     *
     * @var bool
     */
    protected $_canAuthorize = true;

    /**
     * Payment Method feature
     *
     * @var bool
     */
    protected $_canCapture = true;

    /**
     * Payment Method feature
     *
     * @var bool
     */
    protected $_canCapturePartial = false;

    /**
     * Payment Method feature
     *
     * @var bool
     */
    protected $_canRefund = false;

    /**
     * Payment Method feature
     *
     * @var bool
     */
    protected $_canVoid = false;

    /**
     * Payment Method feature
     *
     * @var bool
     */
    protected $_canUseInternal = true;

    /**
     * Payment Method feature
     *
     * @var bool
     */
    protected $_canUseCheckout = true;

    /**
     * Payment Method feature
     *
     * @var bool
     */
    protected $_isInitializeNeeded = true;

    /**
     * Need to run payment initialize method before order place
     *
     * @var bool
     */
    protected $_isGatewayAction = true;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $urlBuilder;

    /**
     * @var DirectoryList
     */
    protected $directoryList;

    /**
     * @var File
     */
    protected $file;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param ExtensionAttributesFactory $extensionFactory
     * @param AttributeValueFactory $customAttributeFactory
     * @param Data $paymentData
     * @param ScopeConfigInterface $scopeConfig
     * @param Logger $logger
     * @param \Magento\Framework\UrlInterface $urlBuilder
     * @param DirectoryList $directoryList
     * @param File $file
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        Data $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        \Magento\Framework\UrlInterface $urlBuilder,
        DirectoryList $directoryList,
        File $file,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->scopeConfig = $scopeConfig;
        $this->urlBuilder = $urlBuilder;
        $this->directoryList = $directoryList;
        $this->file = $file;
    }

    /**
     * Check if logging is enabled
     *
     * @return bool
     */
    protected function isLoggingEnabled()
    {
        return (bool) $this->getConfigData('enable_log');
    }

    /**
     * Log message to paydibs.log if logging is enabled
     *
     * @param string $message
     * @return void
     */
    public function log($message)
    {
        if (!$this->isLoggingEnabled()) {
            return;
        }

        try {
            $logPath = $this->directoryList->getPath('var') . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . self::LOG_FILE;
            $timestamp = date('Y-m-d H:i:s');
            $formattedMessage = "[{$timestamp}] [Paydibs] {$message}" . PHP_EOL;
            $this->file->filePutContents($logPath, $formattedMessage, FILE_APPEND);
        } catch (\Exception $e) {
            $this->_logger->error($e->getMessage());
        }
    }

    /**
     * Get API URL based on environment setting
     *
     * @return string
     */
    public function getApiUrl()
    {
        $environment = $this->getConfigData('api_environment');
        if ($environment === 'test') {
            return $this->getConfigData('test_api_url');
        }
        return $this->getConfigData('production_api_url');
    }

    /**
     * Get Merchant ID from configuration
     *
     * @return string
     */
    public function getMerchantId()
    {
        return $this->getConfigData('merchant_id');
    }

    /**
     * Get Page Timeout from configuration
     *
     * @return int
     */
    public function getPageTimeout()
    {
        $timeout = $this->getConfigData('page_timeout');
        return empty($timeout) ? 300 : (int)$timeout;
    }
    
    /**
     * Get Merchant Password from configuration
     *
     * @return string
     */
    public function getMerchantPassword()
    {
        return $this->getConfigData('merchant_password');
    }

    /**
     * Method that will be executed instead of authorize or capture
     * if flag isInitializeNeeded set to true
     *
     * @param string $paymentAction
     * @param object $stateObject
     *
     * @return $this
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function initialize($paymentAction, $stateObject)
    {
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $this->getInfoInstance();
        
        /** @var \Magento\Sales\Model\Order $order */
        $order = $payment->getOrder();
        
        $this->log('Initialize payment method for order #' . $order->getIncrementId());
        
        $order->setCanSendNewEmailFlag(false);
        
        $stateObject->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);
        $redirectUrl = $this->urlBuilder->getUrl('paydibs/payment/prepare', ['_secure' => true]);
        $payment->setAdditionalInformation(
            'redirect_url',
            $redirectUrl
        );
        
        return $this;
    }

    /**
     * Get redirect URL
     * 
     * Return null since we're using the Prepare controller for redirection
     * instead of Magento's core redirect mechanism
     *
     * @return string|null
     */
    public function getOrderPlaceRedirectUrl()
    {
        return null;
    }
    
    /**
     * Check if cart restoration is enabled
     *
     * @return bool
     */
    public function isCartRestorationEnabled()
    {
        return (bool) $this->getConfigData('restore_cart');
    }
}
