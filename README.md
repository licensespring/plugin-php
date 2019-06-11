# LicenseSpring-PHP-Plugin
Plugin written in PHP used to activate licenses on LicenseSpring using order data from PayPal 

# Installation
`composer require license-spring/paypal-plugin'`

## Usage
```
require_once 'vendor/autoload.php';
use LicenseSpring\LSWebhook;

$webhook = new LSWebhook("your_api_key", "your_secret_key");

$payload = file_get_contents('php://input');
$result = $webhook->createOrder($payload);

header('Content-type:application/json');
echo json_encode($result);
```
