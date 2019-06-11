<?php

namespace LicenseSpring;

class LSWebhook {

    private $api_key, $secret_key;

    private static $order_successful_msg = "License keys successfuly activated.";
    private static $order_error_msg = "There was a problem activating your license keys. Please contact LicenseSpring.";

    private static $backoff_steps = 10, $backoff_wait_time = 100; # in miliseconds

    private static $api_host = "https://api.licensespring.com";
    private static $license_endpoint = "/api/v3/webhook/license";
    private static $order_endpoint = "/api/v3/webhook/order";

    function __construct($api_key, $secret_key) {
        $this->api_key = $api_key;
        $this->secret_key = $secret_key;
    }

    private function sign($datestamp) {
        $data = "licenseSpring\ndate: $datestamp";
        $hashed = hash_hmac('sha256', $data, $this->secret_key, $raw_output = true);
        return base64_encode($hashed);
    }

    /*
    used by makeRequest().
    */
    private static function generateResponsePrivate($curl_obj, $success, $msg = null) {
        curl_close($curl_obj);
        return (object) array(
            "success" => $success,
            "message" => $msg,
        );
    }

    private static function makeRequest($request_type, $api, $data, $headers) {
        if ($request_type == "POST") {
            $ch = curl_init(self::$api_host . $api);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } else {
            $ch = curl_init(self::$api_host . $api . "?" . http_build_query($data));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $res = curl_exec($ch);
        if ( ! ($res)) {
            return self::generateResponsePrivate($ch, $success = false, $msg = curl_error($ch));
        }
        $info = curl_getinfo($ch);
        if ($info['http_code'] != 201 && $info['http_code'] != 200) {
            return self::generateResponsePrivate($ch, $success = false, $msg = $res);
        }
        return self::generateResponsePrivate($ch, $success = true, $msg = $res);
    }

    private static function exponentialBackoff($request_type, $api, $data, $headers, $counter) {
        $response = self::makeRequest($request_type, $api, $data, $headers);
        if ($response->success) {
            return $response;
        }
        if ($counter + 1 < self::$backoff_steps) {
            usleep($counter * self::$backoff_wait_time * 1000);
            return self::exponentialBackoff($request_type, $api, $data, $headers, $counter + 1);
        }
        return $response;
    }

    /*
    POST /order.
    */
    private static function checkPayPalResponseForErrors($payload) {
        $json = json_decode($payload);
        if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("PayPal response has invalid JSON format.");
        }
        if (!array_key_exists("purchase_units", $json)) {
            throw new Exception("PayPal response missing 'purchase_units' object.");
        }
        if (count($json->purchase_units) == 0) {
            throw new Exception("PayPal response missing 'purchase_units' data.");
        }
        if (!array_key_exists("items", $json->purchase_units[0])) {
            throw new Exception("PayPal response missing 'items' object.");
        }
        return $json;
    }

    /*
    POST /order.
    returns string representation of JSON object.
    */
    public function generateOrderFromPayPal($payload) {
        $response = self::checkPayPalResponseForErrors($payload);

        $paypal_order_id = array_key_exists("id", $response) ? $response->id : "id";
        $purchase_unit = $response->purchase_units[0];
        $order_reference = array_key_exists("reference_id", $purchase_unit) ? $purchase_unit->reference_id : bin2hex(uniqid());

        $products_licenses = (object) array();
        // from separate product objects with the same product_code, each containing only 1 license, create one product object with all licenses
        foreach($purchase_unit->items as $item) {
            if (array_key_exists("sku", $item)) {
                $items = explode(";", base64_decode($item->sku));
                if (count($items) == 2) {
                    $product_code = $items[0];
                    $license_key = $items[1];
        
                    if (array_key_exists($product_code, $products_licenses)) {
                        $licenses = array_merge($products_licenses->$product_code, array(array("key" => $license_key)));
                    } else {
                        $licenses = array(array("key" => $license_key));
                    }
                    $products_licenses->$product_code = $licenses;
                }
            }
        }
        // basic order data
        $order_data = (object) array();
        $order_data->id = $order_reference . "_paypal_" . $paypal_order_id;
        $order_data->created = array_key_exists("create_time", $response) ? date("Y-m-j H:i:s", strtotime($response->create_time)) : "";
        $order_data->append = true;

        // customer data
        if (array_key_exists("payer", $response)) {
            $order_data->customer = (object) array();
            $order_data->customer->email = array_key_exists("email_address", $response->payer) ? $response->payer->email_address : "";

            if (array_key_exists("name", $response->payer)) {
                $order_data->customer->first_name = array_key_exists("given_name", $response->payer->name) ? $response->payer->name->given_name : "";
                $order_data->customer->last_name = array_key_exists("surname", $response->payer->name) ? $response->payer->name->surname : "";
            }
        }
        // order items
        $order_data->items = array();
        foreach($products_licenses as $key => $value) {
            array_push($order_data->items, array(
                "product_code" => $key,
                "licenses" => $value,
            ));
        }
        return json_encode($order_data);
    }
    
    /*
    generate response for frontend with success and message.
    */
    public function generateResponse($success, $message) {
        return json_encode(array(
            "success" => $success,
            "message" => $message,
        ), JSON_PRETTY_PRINT);
    }

    /*
    POST /order.
    extracts error message from webhook response.
    */
    private function webhookResponseToFrontendResponse($res) {
        $res = (object) $res;
        if ($res->success == true) {
            $message = self::$order_successful_msg;
        } else {
            $res_error = json_decode($res->message);
            if ($res_error !== null && array_key_exists("errors", $res_error) && count($res_error->errors) > 0 && array_key_exists("message", $res_error->errors[0]) && array_key_exists("value", $res_error->errors[0])) {
                $message = $res_error->errors[0]->message . ": " . $res_error->errors[0]->value;
            } else {
                $message = self::$order_error_msg;
            }
        }
        return $this->generateResponse($res->success, $message);
    }

    /*
    POST /order.
    */
    public function createOrder($order_data) {
        $date_header = date("D, j M Y H:i:s") . " GMT";
        $signing_key = $this->sign($date_header);

        $auth = array(
            'algorithm="hmac-sha256"',
            'headers="date"',
            strtr('signature="@key"', ["@key" => $signing_key]),
            strtr('apiKey="@key"', ["@key" => $this->api_key]),
        );
        $headers = array(
            'Date: ' . $date_header, 
            'Authorization: ' . implode(",", $auth),
            'Content-Type: application/json',
        );

        $ls_webhook_response = self::exponentialBackoff("POST", self::$order_endpoint, $order_data, $headers, $counter = 1);
        return $this->webhookResponseToFrontendResponse($ls_webhook_response);
    }

    /*
    GET /license.
    */
    private static function checkFrontendResponseForErrors($payload) {
        $json = json_decode($payload);
        if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON format.");
        }
        if (!array_key_exists("products", $json)) {
            throw new Exception("Data is missing 'products' object.");
        }
        return $json;
    }

    /*
    GET /license.
    */
    private static function insertLiceneKeysInJSON($json, $headers) {
        foreach($json->products as $product) {
            if (array_key_exists("quantity", $product) && array_key_exists("code", $product)) {
                $license_request = array(
                    "product" => $product->code, 
                    "quantity" => $product->quantity,
                );
                $webhook_response = self::exponentialBackoff("GET", self::$license_endpoint, $license_request, $headers, $counter = 1);
                if ($webhook_response->success) {
                    // TODO base64??
                    $product->licenses = json_decode($webhook_response->message);
                } else {
                    throw new Exception("There was a problem obtaining license codes from LicenseSpring: " . $webhook_response->message);
                }
            } else {
                // TODO check this on /POST as well
                throw new Exception("Product must have quantity and code values.");
            }
        }
        return $json;
    }

    /*
    GET /licence.
    */
    public function getLicenseKeys($payload) {
        $json = self::checkFrontendResponseForErrors($payload);

        $date_header = date("D, j M Y H:i:s") . " GMT";
        $signing_key = $this->sign($date_header);

        $auth = array(
            'algorithm="hmac-sha256"',
            'headers="date"',
            strtr('signature="@key"', ["@key" => $signing_key]),
            strtr('apiKey="@key"', ["@key" => $this->api_key]),
        );
        $headers = array(
            'Date: ' . $date_header, 
            'Authorization: ' . implode(",", $auth),
            'Content-Type: application/json',
        );

        $json = self::insertLiceneKeysInJSON($json, $headers);
        return $this->generateResponse($success = true, $message = json_encode($json));
    }
}
