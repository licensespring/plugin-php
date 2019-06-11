<?php

namespace LicenseSpring;

class LSWebhook {

    private $api_key, $secret_key;

    private static $order_successful_msg = "License keys successfuly activated.";
    private static $order_error_msg = "There was a problem activating your license keys. Please contact LicenseSpring.";

    private static $backoff_steps = 10, $backoff_wait_time = 100; # in miliseconds

    private static $api_host = "https://api.licensespring.com";
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

    private static function generateResponseFromPostRequest($curl_obj, $success, $error_msg = null) {
        curl_close($curl_obj);
        return (object) array(
            "success" => $success,
            "error" => $error_msg,
        );
    }

    private static function makePostRequest($api, $data, $headers) {
        $ch = curl_init(self::$api_host . $api);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $res = curl_exec($ch);
        if ( ! ($res)) {
            return self::generateResponseFromPostRequest($ch, $success = false, $error_msg = curl_error($ch));
        }
        $info = curl_getinfo($ch);
        if ($info['http_code'] != 201) {
            return self::generateResponseFromPostRequest($ch, $success = false, $error_msg = $res);
        }
        return self::generateResponseFromPostRequest($ch, $success = true);
    }

    private static function exponentialBackoff($api, $data, $headers, $counter) {
        $response = self::makePostRequest($api, $data, $headers);
        if ($response->success) {
            return $response;
        }
        if ($counter + 1 < self::$backoff_steps) {
            usleep($counter * self::$backoff_wait_time * 1000);
            return self::exponentialBackoff($api, $data, $headers, $counter + 1);
        }
        return $response;
    }

    private static function generateLSOrderDataFromPayPalOrderData($response) {
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
        return $order_data;
    }

    /*
    if custom_message is set, return custom_message. else, use predefined message or LS error message.
    */
    private static function generateResponseForFrontend($res, $custom_message = null) {
        $res = (object) $res;
        if ($res->success == true) {
            $message = self::$order_successful_msg;
        } else {
            $res_error = json_decode($res->error);
            if ($res_error !== null && array_key_exists("errors", $res_error) && count($res_error->errors) > 0 && array_key_exists("message", $res_error->errors[0]) && array_key_exists("value", $res_error->errors[0])) {
                $message = $res_error->errors[0]->message . ": " . $res_error->errors[0]->value;
            } else {
                $message = self::$order_error_msg;
            }
        }
        return array(
            "success" => $res->success,
            "message" => $custom_message !== null ? $custom_message : $message,
        );
    }

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

    public function createOrder($payload) {
        try {
            $payload = self::checkPayPalResponseForErrors($payload);
        } catch (Exception $exc) {
            return self::generateResponseForFrontend(array("success" => false), $custom_message = $exc->getMessage());
        }
        $order_data = json_encode(self::generateLSOrderDataFromPayPalOrderData($payload));

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

        $ls_webhook_response = self::exponentialBackoff(self::$order_endpoint, $order_data, $headers, $counter = 1);
        return self::generateResponseForFrontend($ls_webhook_response);
    }
}
