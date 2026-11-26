<?php

namespace Midtrans;

/**
 * API methods to get transaction status, approve and cancel transactions
 */
class Transaction
{

    /**
     * Retrieve transaction status
     * 
     * @param string $id Order ID or transaction ID
     * 
     * @return mixed[]
     */
    public static function status($id, $payment_type = null)
    {
        return ApiRequestor::get(
            Config::getBaseUrl() . '/' . self::encodeId($id) . '/status',
            Config::$serverKey,
            false,
            self::isOpenApi($payment_type)
        );
    }

    /**
     * Approve challenge transaction
     * 
     * @param string $id Order ID or transaction ID
     * 
     * @return string
     */
    public static function approve($id)
    {
        return ApiRequestor::post(
            Config::getBaseUrl() . '/' . self::encodeId($id) . '/approve',
            Config::$serverKey,
            false
        )->status_code;
    }

    /**
     * Cancel transaction before it's settled
     * 
     * @param string $id Order ID or transaction ID
     * 
     * @return string
     */
    public static function cancel($id)
    {
        return ApiRequestor::post(
            Config::getBaseUrl() . '/' . self::encodeId($id) . '/cancel',
            Config::$serverKey,
            false
        )->status_code;
    }
  
    /**
     * Expire transaction before it's setteled
     * 
     * @param string $id Order ID or transaction ID
     * 
     * @return mixed[]
     */
    public static function expire($id)
    {
        return ApiRequestor::post(
            Config::getBaseUrl() . '/' . self::encodeId($id) . '/expire',
            Config::$serverKey,
            false
        );
    }

    /**
     * Transaction status can be updated into refund
     * if the customer decides to cancel completed/settlement payment.
     * The same refund id cannot be reused again.
     * 
     * @param string $id Order ID or transaction ID
     * 
     * @return mixed[]
     */
    public static function refund($id, $params, $payment_type = null)
    {
        return ApiRequestor::post(
            Config::getBaseUrl() . '/' . self::encodeId($id) . '/refund',
            Config::$serverKey,
            $params,
            self::isOpenApi($payment_type)
        );
    }

    /**
     * Transaction status can be updated into refund
     * if the customer decides to cancel completed/settlement payment.
     * The same refund id cannot be reused again.
     * 
     * @param string $id Order ID or transaction ID
     * 
     * @return mixed[]
     */
    public static function refundDirect($id, $params)
    {
        return ApiRequestor::post(
            Config::getBaseUrl() . '/' . self::encodeId($id) . '/refund/online/direct',
            Config::$serverKey,
            $params
        );
    }

    /**
     * Deny method can be triggered to immediately deny card payment transaction
     * in which fraud_status is challenge.
     * 
     * @param string $id Order ID or transaction ID
     * 
     * @return mixed[]
     */
    public static function deny($id)
    {
        return ApiRequestor::post(
            Config::getBaseUrl() . '/' . self::encodeId($id) . '/deny',
            Config::$serverKey,
            false
        );
    }

    private static function isOpenApi($payment_type): bool
    {
        return is_string($payment_type) && strtolower($payment_type) == "dana";
    }

    /**
     * Encode Order ID / transaction ID as a single URL path segment.
     * The ID may originate from an HTTP request, so it must never be able to
     * change the API path (e.g. `../`, `?`, `#`).
     *
     * @param string $id Order ID or transaction ID
     *
     * @return string
     * @throws \Exception when the ID is empty or not a scalar value
     */
    private static function encodeId($id)
    {
        if (!is_scalar($id) || trim((string) $id) === '') {
            throw new \Exception('Midtrans Error: invalid order ID or transaction ID');
        }
        return rawurlencode(trim((string) $id));
    }

}
