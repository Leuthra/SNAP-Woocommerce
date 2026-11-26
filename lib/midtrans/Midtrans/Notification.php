<?php

namespace Midtrans;

/**
 * Read raw post input and parse as JSON. Provide getters for fields in notification object
 *
 * The notification body itself is never trusted: the transaction status is always
 * re-fetched from Midtrans API using the `transaction_id` found in the body.
 *
 * Example:
 *
 * ```php
 * 
 *   namespace Midtrans;
 * 
 *   $notif = new Notification();
 *   echo $notif->order_id;
 *   echo $notif->transaction_status;
 * ```
 */
class Notification
{
    private $response;

    /**
     * @param string|array $input_source stream to read the JSON body from,
     * or the already decoded notification body as array
     */
    public function __construct($input_source = "php://input")
    {
        $raw_notification = is_array($input_source)
            ? $input_source
            : json_decode(file_get_contents($input_source), true);

        if (!is_array($raw_notification)
            || !isset($raw_notification['transaction_id'])
            || !is_string($raw_notification['transaction_id'])
            || $raw_notification['transaction_id'] === ''
        ) {
            throw new \Exception('Midtrans Error: invalid notification body, `transaction_id` is missing');
        }
        $payment_type = (isset($raw_notification['payment_type']) && is_string($raw_notification['payment_type']))
            ? $raw_notification['payment_type']
            : null;

        $status_response = Transaction::status($raw_notification['transaction_id'], $payment_type);
        $this->response = $status_response;
    }

    public function __get($name)
    {
        if (isset($this->response->$name)) {
            return $this->response->$name;
        }
    }

    public function __isset($name)
    {
        return isset($this->response->$name);
    }

    public function getResponse()
    {
        return $this->response;
    }
}
