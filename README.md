# LicenseSpring-PHP-Plugin
Plugin written in PHP used to create and activate licenses on LicenseSpring using order data from PayPal

## Installation
```bash
composer require licensespring/php-plugin
```

## Usage
### Get license keys from LicenseSpring platform
Create this PHP script as an API script.

```php
<?php
// your script will return JSON string
header('Content-type:application/json');

// require autoload.php.
// set path differently if your PHP script doesn't reside in document root.
// for instance, if your PHP script is in /php/api/v1/ folder relative to the document root then use: require_once '../../../vendor/autoload.php';
require_once 'vendor/autoload.php';

// use LicenseSpring namespace
use LicenseSpring\LSWebhook;

// initialize new LSWebhook object and give it your api_key and your secret_key (provided by LicenseSpring platform)
$webhook = new LSWebhook("api_key", "secret_key");

// capture any POST data (should be from your frontend part)
$frontend_payload = file_get_contents('php://input');

// if POST data was not from your frontend or was wrong format, getLicenseKeys() method will throw an exception so be sure to check for that too.
try {
    // this will obtain license keys for sent products echo that back to frontend.
    echo $webhook->getLicenseKeys($frontend_payload);
} catch (\Exception $e) {
    // in this case, just generate and echo a failure response for frontend part of your app.
    echo $webhook->generateResponse($success = false, $message = $e->getMessage());
}
```

### Create order on LicenseSpring platform
Create this PHP script as an API script.

```php
<?php
// your script will return JSON string
header('Content-type:application/json');

// require autoload.php.
// set path differently if your PHP script doesn't reside in document root.
// for instance, if your PHP script is in /php/api/v1/ folder relative to the document root then use: require_once '../../../vendor/autoload.php';
require_once 'vendor/autoload.php';

// use LicenseSpring namespace
use LicenseSpring\LSWebhook;

// initialize new LSWebhook object and give it your api_key and your secret_key (provided by LicenseSpring platform)
$webhook = new LSWebhook("api_key", "secret_key");

// capture any POST data (should be from PayPal only)
$paypal_payload = file_get_contents('php://input');

// if POST data was not from PayPal, generateOrderFromPayPal() method will throw an exception so be sure to check for that too.
try {
    // this will "translate" PayPal order data to valid LicenseSpring order data
    $ls_order_data = $webhook->generateOrderFromPayPal($paypal_payload);
} catch (Exception $e) {
    // in this case, just generate and echo a failure response for frontend part of your app and exit PHP script.
    echo $webhook->generateResponseForFrontend($success = false, $message = $e->getMessage());
    exit();
}
// this creates order in LicenseSpring platform and returns response.
$response = $webhook->createOrder($ls_order_data);

// return this response to frontend.
echo $response;
```