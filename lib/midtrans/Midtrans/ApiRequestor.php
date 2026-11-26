<?php

namespace Midtrans;

/**
 * Send request to Midtrans API
 * Better don't use this class directly, use CoreApi, Transaction
 */

class ApiRequestor
{
    
    /**
     * Send GET request
     * 
     * @param string  $url
     * @param string  $server_key
     * @param mixed[] $data_hash
     */
    public static function get($url, $server_key, $data_hash, $is_open_api = false)
    {
        return self::remoteCall($url, $server_key, $data_hash, false, $is_open_api);
    }

    /**
     * Send POST request
     * 
     * @param string  $url
     * @param string  $server_key
     * @param mixed[] $data_hash
     */
    public static function post($url, $server_key, $data_hash, $is_open_api = null)
    {
        return self::remoteCall($url, $server_key, $data_hash, true, $is_open_api);
    }

    /**
     * Actually send request to API server
     * 
     * @param string  $url
     * @param string  $server_key
     * @param mixed[] $data_hash
     * @param bool    $post
     */
    public static function remoteCall($url, $server_key, $data_hash, $post = true, $is_open_api = null)
    {
        if (!is_string($server_key) || trim($server_key) === '') {
            throw new \Exception('Midtrans Error: Server Key is empty, please set it on the Midtrans plugin configuration page');
        }

        $ch = curl_init();

        $headers = array(
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($server_key . ':'),
            'X-Source: Woocommerce',
            'X-Source-Version: ' . MIDTRANS_PLUGIN_VERSION
        );
        $openApiHeaders =  array(
            'transaction-source: SNAP_API');

        if ($is_open_api){
            $headers = array_merge($headers, $openApiHeaders);
        }

        $curl_options = array(
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30
        );

        // merging with Config::$curlOptions
        if (count(Config::$curlOptions)) {
            // We need to combine headers manually, because it's array and it will no be merged
            $headerOptions = array();
            if (!empty(Config::$curlOptions[CURLOPT_HTTPHEADER])) {
                $mergedHeders = array_merge($curl_options[CURLOPT_HTTPHEADER], Config::$curlOptions[CURLOPT_HTTPHEADER]);
                $headerOptions = array( CURLOPT_HTTPHEADER => $mergedHeders );
            }

            $curl_options = array_replace_recursive($curl_options, Config::$curlOptions, $headerOptions);
        }

        if ($post) {
            $curl_options[CURLOPT_POST] = 1;

            if ($data_hash) {
                $body = json_encode($data_hash);
                $curl_options[CURLOPT_POSTFIELDS] = $body;
            } else {
                $curl_options[CURLOPT_POSTFIELDS] = '';
            }
        }

        curl_setopt_array($ch, $curl_options);

        // For testing purpose
        if (class_exists('\Midtrans\VT_Tests') && VT_Tests::$stubHttp) {
            $result = self::processStubed($curl_options, $url, $server_key, $data_hash, $post);
        } else {
            $result = curl_exec($ch);
            // curl_close($ch);
        }


        if ($result === false) {
            throw new \Exception('CURL Error: ' . curl_error($ch), curl_errno($ch));
        } else {
            $result_array = json_decode($result);
            // json_decode does not throw, it returns null on undecodable response
            if (!is_object($result_array) || !isset($result_array->status_code)) {
                throw new \Exception('Midtrans Error: unexpected API response | HTTP status: ' . curl_getinfo($ch, CURLINFO_HTTP_CODE));
            }
            if (!isset($result_array->status_message)) {
                $result_array->status_message = '';
            }
            if (!in_array($result_array->status_code, array(200, 201, 202, 407))) {
                $message = 'Midtrans Error (' . $result_array->status_code . '): '
                . $result_array->status_message;
                if (isset($result_array->validation_messages)) {
                    $message .= '. Validation Messages (' . implode(", ", $result_array->validation_messages) . ')';
                }
                if (isset($result_array->error_messages)) {
                    $message .= '. Error Messages (' . implode(", ", $result_array->error_messages) . ')';
                }
                throw new \Exception($message, (int) $result_array->status_code);
            } else {
                return $result_array;
            }
        }
    }

    private static function processStubed($curl, $url, $server_key, $data_hash, $post)
    {
        VT_Tests::$lastHttpRequest = array(
            "url" => $url,
            "server_key" => $server_key,
            "data_hash" => $data_hash,
            "post" => $post,
            "curl" => $curl
        );

        return VT_Tests::$stubHttpResponse;
    }
}
