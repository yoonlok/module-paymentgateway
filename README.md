# Paydibs Payment Gateway for Magento 2

A Magento 2 extension that integrates Paydibs payment gateway with your Magento store.

## Features

- Seamless integration with Paydibs payment gateway
- Support for various payment methods through Paydibs
- Server-side redirect for improved payment flow
- Compatible with Magento 2.4.x

## Installation

### Using Composer

```bash
composer require paydibs/module-paymentgateway
bin/magento module:enable Paydibs_PaymentGateway
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:clean
```

## Configuration

1. Go to Stores > Configuration > Sales > Payment Methods
2. Find "Paydibs Payment Gateway" section
3. Configure your Paydibs merchant credentials
4. Save configuration

