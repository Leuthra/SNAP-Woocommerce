<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Midtrans_API class.
 * @TODO: refactor this messy class
 * 
 * Communicates with Midtrans API.
 */
class WC_Midtrans_API {

	/**
	 * Server Key.
	 * @var string
	 */
	private static $server_key = '';

	/**
	 * Midtrans Environment.
	 * @var string
	 */
    private static $environment = '';
    
	/**
	 * Plugin Options.
	 * @var string
	 */
	private static $plugin_options;
	
	/**
	 * Set Server Key.
	 * @param string $key
	 */
	public static function set_server_key( $server_key ) {
		self::$server_key = $server_key;
    }

	/**
	 * Set Midtrans Environment.
	 * @param string $key
	 */
	public static function set_environment( $environment ) {
		self::$environment = $environment;
    }

    // @TODO: maybe handle when $plugin_id is invalid (e.g: `all`), it result in invalid $plugin_options, then empty serverKey, then it will cause failure on getStatusFromMidtransNotif. Make $plugin_options default value to `midtrans` plugin when serverKey not found?.
	/**
	 * Fetch Plugin Options and Set as self/private vars
	 * @param string $plugin_id
	 */
	public static function fetchAndSetCurrentPluginOptions ( $plugin_id="midtrans" ) {
		self::$plugin_options = get_option( 'woocommerce_' . $plugin_id . '_settings' );
		// each gateway may have its own keys & environment, don't reuse the values
		// cached from another gateway that was used earlier on the same request
		self::$server_key = '';
		self::$environment = '';
	}

	/**
	 * Get Server Key.
	 * @return string
	 */
	public static function get_server_key() {
		if ( ! self::$server_key ) {
			$plugin_options = self::$plugin_options;
			if ( isset( $plugin_options['server_key_v2_production'], $plugin_options['server_key_v2_sandbox'] ) ) {
				self::set_server_key( self::get_environment() == 'production' ? $plugin_options['server_key_v2_production'] : $plugin_options['server_key_v2_sandbox'] );
			}
		}
		return self::$server_key;
	}

    /**
	 * Get Midtrans Environment.
	 * @return string
	 */
	public static function get_environment() {
		if ( ! self::$environment ) {
			$plugin_options = self::$plugin_options;
			if ( isset( $plugin_options['select_midtrans_environment'] ) ) {
				self::set_environment( $plugin_options['select_midtrans_environment'] );
			}
		}
		return self::$environment;
	}

    /**
     * Fetch Midtrans API Configuration from plugin id and set as self/private vars.
     * @return void
     */
    public static function fetchAndSetMidtransApiConfig( $plugin_id="midtrans" ) {
        if(strpos($plugin_id, 'midtrans_sub') !== false){
          // for sub separated gateway buttons, use main gateway plugin id instead
          $plugin_id = 'midtrans';
        }
		self::fetchAndSetCurrentPluginOptions( $plugin_id );
        Midtrans\Config::$isProduction = (self::get_environment() == 'production') ? true : false;
        Midtrans\Config::$serverKey = self::get_server_key();     
        Midtrans\Config::$isSanitized = true;

        // setup custom HTTP client header as identifier ref:
        // https://github.com/omarxp/Midtrans-Drupal8/blob/3d4e4b4af46e96c742667c7a2925cf70dfaa9e2a/src/PluginForm/MidtransOfflineInstallmentForm.php#L39-L42
        // assigned (not appended), so the headers are not duplicated when this is called multiple times
        Midtrans\Config::$curlOptions[CURLOPT_HTTPHEADER] = array(
            'x-midtrans-wc-plu-version: '.MIDTRANS_PLUGIN_VERSION,
            'x-midtrans-wc-plu-wc-version: '.WC_VERSION,
            'x-midtrans-wc-plu-php-version: '.phpversion(),
        );
    }

    /**
     * Get Signature Key of current environment. Newer Midtrans account have a Signature Key that is
     * separated from Server Key, it is the one used to calculate `signature_key` of notification.
     * @return string empty string if not configured
     */
    public static function get_signature_key() {
        $plugin_options = self::$plugin_options;
        $option_name = self::get_environment() == 'production' ? 'signature_key_production' : 'signature_key_sandbox';
        return ( is_array( $plugin_options ) && isset( $plugin_options[ $option_name ] ) && is_string( $plugin_options[ $option_name ] ) )
            ? trim( $plugin_options[ $option_name ] ) : '';
    }

    /**
     * Verify `signature_key` of Midtrans HTTP notification body.
     * Formula: SHA512(order_id + status_code + gross_amount + key)
     * https://docs.midtrans.com/docs/https-notification-webhooks
     * The key is the Signature Key if it is configured, else the Server Key (older Midtrans account).
     *
     * @param  array  $raw_notification decoded JSON body of the HTTP notification
     * @param  string $plugin_id ID of the gateway used to pay the order
     * @return string `valid`: signed by this merchant's key.
     *                `invalid`: Signature Key is configured, and the signature doesn't match.
     *                `unknown`: can't be verified locally (Signature Key is not configured, and
     *                           the signature is not made with Server Key).
     */
    public static function getNotificationSignatureState( $raw_notification, $plugin_id="midtrans" ) {
        self::fetchAndSetMidtransApiConfig( $plugin_id );
        $signature_key = self::get_signature_key();
        $server_key = (string) Midtrans\Config::$serverKey;

        $signed_string = null;
        if ( is_array( $raw_notification ) ) {
            $signed_string = '';
            foreach ( array( 'order_id', 'status_code', 'gross_amount' ) as $field ) {
                if ( ! isset( $raw_notification[ $field ] ) || ! is_scalar( $raw_notification[ $field ] ) ) {
                    $signed_string = null;
                    break;
                }
                $signed_string .= $raw_notification[ $field ];
            }
        }
        $signature = ( is_array( $raw_notification ) && isset( $raw_notification['signature_key'] ) && is_string( $raw_notification['signature_key'] ) )
            ? strtolower( $raw_notification['signature_key'] ) : null;
        $is_signed_with = function( $key ) use ( $signed_string, $signature ) {
            return $key !== '' && $signed_string !== null && $signature !== null
                && hash_equals( hash( 'sha512', $signed_string . $key ), $signature );
        };

        if ( $signature_key !== '' ) {
            return $is_signed_with( $signature_key ) ? 'valid' : 'invalid';
        }
        return $is_signed_with( $server_key ) ? 'valid' : 'unknown';
    }

    /**
     * Check directly to Snap API whether a Midtrans transaction_id is the transaction that was made
     * using the Snap token of an order. Used to verify notification of BI-SNAP & DANA payment when
     * the signature can't be verified (Signature Key is not configured).
     * The Snap token is only known by this site & the customer who made the order.
     *
     * @param  object $order the WC Order instance.
     * @param  string $midtrans_order_id order_id mentioned on the notification body
     * @param  string $transaction_id transaction_id mentioned on the notification body
     * @param  string $plugin_id ID of the gateway used to pay the order
     * @return bool
     */
    public static function isTransactionOfOrderSnapToken( $order, $midtrans_order_id, $transaction_id, $plugin_id="midtrans" ) {
        $snap_token = is_object( $order ) ? WC_Midtrans_Utils::sanitize_snap_token( $order->get_meta( '_mt_payment_snap_token' ) ) : '';
        if ( $snap_token === '' || ! is_string( $transaction_id ) || $transaction_id === '' ) {
            return false;
        }
        self::fetchAndSetMidtransApiConfig( $plugin_id );
        $response = wp_remote_get(
            Midtrans\Config::getSnapBaseUrl() . '/transactions/' . rawurlencode( $snap_token ) . '/status',
            array( 'timeout' => 15, 'redirection' => 0, 'headers' => array( 'Accept' => 'application/json' ) )
        );
        if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return false;
        }
        $snap_status = json_decode( wp_remote_retrieve_body( $response ) );
        return is_object( $snap_status )
            && isset( $snap_status->transaction_id, $snap_status->order_id )
            && is_string( $snap_status->transaction_id ) && is_scalar( $snap_status->order_id )
            && hash_equals( $snap_status->transaction_id, $transaction_id )
            && (string) $snap_status->order_id === (string) $midtrans_order_id;
    }

    /**
     * @param  array  $raw_notification decoded JSON body of the HTTP notification
     * @param  string $plugin_id ID of the gateway used to pay the order
     * @return bool   true if the notification is signed with this merchant's key
     */
    public static function isNotificationSignatureValid( $raw_notification, $plugin_id="midtrans" ) {
        return self::getNotificationSignatureState( $raw_notification, $plugin_id ) === 'valid';
    }

    /**
     * Same as createSnapTransaction, but it will auto handle exception
     * 406 duplicated order_id exception from Snap API, by calling WC_Midtrans_Utils::generate_non_duplicate_order_id
     * @param  object $order the WC Order instance.
     * @param  array $params Payment options.
     * @param  string $plugin_id ID of the plugin class calling this function
     * @return object Snap response (token and redirect_url).
     * @throws Exception curl error or midtrans error.
     */
    public static function createSnapTransactionHandleDuplicate( $order, $params, $plugin_id="midtrans") {
        try {
            $response = self::createSnapTransaction($params, $plugin_id);
        } catch (Exception $e) {
            // Handle: Snap order_id duplicated, retry with suffixed order_id
            if( strpos($e->getMessage(), 'transaction_details.order_id sudah digunakan') !== false) {
                self::setLogRequest( $e->getMessage().' - Attempt to auto retry with suffixed order_id', $plugin_id );
                // @TAG: order-id-suffix-handling
                $params['transaction_details']['order_id'] = 
                    WC_Midtrans_Utils::generate_non_duplicate_order_id($params['transaction_details']['order_id']);
                $response =  self::createSnapTransaction($params, $plugin_id);
                
                // store the suffixed order id to order metadata
                // @TAG: order-id-suffix-handling-meta
                $order->update_meta_data('_mt_suffixed_midtrans_order_id', $params['transaction_details']['order_id']);
            } else {
                throw $e;
            }
        }
        self::rememberExpectedGrossAmount( $order, $params );
        // store the (unsuffixed) order_id sent to Midtrans, so the WC order can be found again on
        // notification handling when custom order_id is used. @TAG: order-id-suffix-handling
        $order->update_meta_data( '_mt_midtrans_order_id',
            WC_Midtrans_Utils::check_and_restore_original_order_id( (string) $params['transaction_details']['order_id'] ) );
        return $response;
    }

    /**
     * Check directly to Snap API whether a Snap token is still usable to pay
     * (exists, not expired, and its transaction is not yet paid/failed).
     * @param  string $snap_token
     * @param  string $plugin_id ID of the gateway used to create the token
     * @return bool
     */
    public static function isSnapTokenActive( $snap_token, $plugin_id="midtrans" ) {
        $snap_token = WC_Midtrans_Utils::sanitize_snap_token( $snap_token );
        if ( $snap_token === '' ) {
            return false;
        }
        self::fetchAndSetMidtransApiConfig( $plugin_id );
        $response = wp_remote_get(
            Midtrans\Config::getSnapBaseUrl() . '/transactions/' . rawurlencode( $snap_token ),
            array( 'timeout' => 10, 'redirection' => 0, 'headers' => array( 'Accept' => 'application/json' ) )
        );
        if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return false;
        }
        $snap_transaction = json_decode( wp_remote_retrieve_body( $response ) );
        return is_object( $snap_transaction ) && isset( $snap_transaction->token ) && $snap_transaction->token === $snap_token;
    }

    /**
     * Fingerprint of Snap API params, to know whether a Snap token previously created for
     * an order is still for the same payment (same amount, items, payment methods, etc)
     * @param  array $params Snap API params
     * @return string
     */
    public static function getSnapParamsHash( $params ) {
        // exclude values that are changing on every request
        unset( $params['expiry']['start_time'], $params['metadata'] );
        return md5( wp_json_encode( $params ) );
    }

    /**
     * Store the amount sent to Snap API as order meta (caller will save the order), keyed by
     * the order_id sent to Midtrans. Used on notification handling to validate the paid amount.
     * @param  object $order the WC Order instance.
     * @param  array $params Snap API params.
     * @return void
     */
    public static function rememberExpectedGrossAmount( $order, $params ) {
        if ( ! is_object( $order ) || ! isset( $params['transaction_details']['order_id'] ) ) {
            return;
        }
        // same calculation as done by Midtrans\Snap::createTransaction
        $gross_amount = isset( $params['transaction_details']['gross_amount'] ) ? $params['transaction_details']['gross_amount'] : 0;
        if ( isset( $params['item_details'] ) && is_array( $params['item_details'] ) ) {
            $gross_amount = 0;
            foreach ( $params['item_details'] as $item ) {
                $gross_amount += $item['quantity'] * $item['price'];
            }
        }
        $expected_amounts = $order->get_meta( '_mt_expected_gross_amounts' );
        if ( ! is_array( $expected_amounts ) ) {
            $expected_amounts = array();
        }
        $expected_amounts[ (string) $params['transaction_details']['order_id'] ] = (float) $gross_amount;
        $order->update_meta_data( '_mt_expected_gross_amounts', $expected_amounts );
    }

    /**
     * Check if amount of a paid Midtrans transaction is less than the amount requested to Snap API
     * for that order_id. Prevent an order being completed by a transaction created for older
     * (cheaper) version of the order.
     * @param  object $order the WC Order instance.
     * @param  string $midtrans_order_id order_id of the Midtrans transaction (may be suffixed).
     * @param  mixed  $paid_gross_amount gross_amount of the Midtrans transaction.
     * @return bool   true if underpaid. false if valid, or expected amount is unknown (e.g: old orders).
     */
    public static function isPaidAmountLessThanExpected( $order, $midtrans_order_id, $paid_gross_amount ) {
        $expected_amounts = $order->get_meta( '_mt_expected_gross_amounts' );
        if ( ! is_array( $expected_amounts ) || ! isset( $expected_amounts[ (string) $midtrans_order_id ] ) ) {
            return false;
        }
        if ( ! is_numeric( $paid_gross_amount ) ) {
            return true;
        }
        return ( (float) $paid_gross_amount + 0.01 ) < (float) $expected_amounts[ (string) $midtrans_order_id ];
    }

    /**
     * Create Snap Token.
     * @param  array $params Payment options.
     * @return object Snap response (token and redirect_url).
     * @throws Exception curl error or midtrans error.
     */
    public static function createSnapTransaction( $params, $plugin_id="midtrans" ) {
        self::fetchAndSetMidtransApiConfig( $plugin_id );
		self::setLogRequest( print_r( $params, true ), $plugin_id );
        return Midtrans\Snap::createTransaction( $params );
	}
	
    /**
     * Create Recurring Transaction for Subscription Payment.
     * @param  array $params Payment options.
     * @return object Core API response (token and redirect_url).
     * @throws Exception curl error or midtrans error.
     */
    public static function createRecurringTransaction( $params, $plugin_id = 'midtrans_subscription' ) {
		self::fetchAndSetMidtransApiConfig( $plugin_id );
		self::setLogRequest( print_r( $params, true ), $plugin_id );
		return Midtrans\CoreApi::charge( $params );
    }

	/**
     * Create Refund.
	 * 
	 * @param int $order_id.
     * @param  array $params Payment options.
     * @return object Refund response.
     * @throws Exception curl error or midtrans error.
     */
    public static function createRefund( $order_id, $params, $plugin_id="midtrans" , $payment_type = null) {
		self::fetchAndSetMidtransApiConfig( $plugin_id );
		self::setLogRequest( print_r( $params, true ), $plugin_id );
		return Midtrans\Transaction::refund($order_id, $params, $payment_type);
    }

    /**
     * Get Midtrans Notification.
     * The status is always re-fetched from Midtrans API, the notification body is only used
     * to know which transaction to fetch.
     * @param  string $plugin_id ID of the gateway used to pay the order
     * @param  array|null $raw_notification decoded JSON body, if null it is read from request body
     * @return object Midtrans Notification response.
     * @throws Exception curl error, midtrans error, or invalid notification body.
     */
    public static function getStatusFromMidtransNotif( $plugin_id="midtrans", $raw_notification = null ) {
        self::fetchAndSetMidtransApiConfig( $plugin_id );
        return is_array( $raw_notification )
            ? new Midtrans\Notification( $raw_notification )
            : new Midtrans\Notification();
    }

    /**
     * Retrieve transaction status. Default ID is main plugin, which is "midtrans"
     * @param string $id Order ID or transaction ID.
     * @return object Midtrans response.
     */
    public static function getMidtransStatus( $order_id, $plugin_id="midtrans", $paymentType = null ) {
        self::fetchAndSetMidtransApiConfig( $plugin_id );
        return Midtrans\Transaction::status( $order_id , $paymentType);
    }

	/**
	 * Cancel transaction.
	 * 
	 * @param string $id Order ID or transaction ID.
	 * @param string $plugin_id Plugin id.
	 * @return object Midtrans response.
	 */
    public static function CancelTransaction( $id, $plugin_id="midtrans" ) {
		self::fetchAndSetMidtransApiConfig( $plugin_id );
		self::setLogRequest('Request Cancel Transaction ' . $id, $plugin_id );
        return Midtrans\Transaction::cancel( $id );
    }

    /**
     * Set log request on midtrans logger.
	 * 
     * @param string $message payload request.
     * @return void
     */
	public static function setLogRequest( $message, $plugin_id="midtrans" ) {
		WC_Midtrans_Logger::log( $message, 'midtrans-request', $plugin_id, current_time( 'timestamp') );
	  }
}