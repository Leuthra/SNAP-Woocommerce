<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC_Gateway_Midtrans_Notif_Handler class.
 * Handles responses from Midtrans Notification.
 * @todo : refactor, this shouldn't be a class
 * maybe just a bunch of function to include in main class
 * to avoid complex param & config value passing
 */
class WC_Gateway_Midtrans_Notif_Handler
// extends WC_Gateway_ 
{
	/**
	 * Constructor.
	 * 
	 */
	public function __construct() {
    // Register hook for handling HTTP notification (HTTP call to `http://[your web]/?wc-api=WC_Gateway_Midtrans`)
		add_action( 'woocommerce_api_wc_gateway_midtrans', array( $this, 'handleMidtransNotificationRequest' ) );
    // Create action to be called when HTTP notification is valid
    // @TODO: rename this hook to use snake_case format
    add_action( 'midtrans-handle-valid-notification', array( $this, 'handleMidtransValidNotificationRequest' ) );
  }
    
  /**
   * Decoded JSON body of the HTTP notification currently being handled
   * @var array|null
   */
  private $raw_notification = null;

  /**
   * Read and decode the JSON body of current HTTP request
   * @return array|null decoded notification, null if the body is not a JSON object
   */
  public function getRawNotification() {
    $raw_notification = json_decode( file_get_contents( 'php://input' ), true );
    return is_array( $raw_notification ) ? $raw_notification : null;
  }

  /**
   * Send plain text HTTP response then stop the request.
   * Request body/params are never printed back to the response, and the response is
   * always sent as `text/plain`, so it can't be used to inject HTML/JS (XSS).
   * @param  int    $http_code
   * @param  string $message static message, never pass user input here
   * @return void
   */
  private function sendPlainResponseAndExit( $http_code, $message ) {
    $this->sendPlainResponseHeaders( $http_code );
    echo esc_html( $message );
    exit;
  }

  /**
   * @param int $http_code
   * @return void
   */
  private function sendPlainResponseHeaders( $http_code ) {
    if ( headers_sent() ) {
      return;
    }
    status_header( $http_code );
    nocache_headers();
    header( 'Content-Type: text/plain; charset=utf-8' );
    header( 'X-Content-Type-Options: nosniff' );
  }

  /**
   * Helper to response Response early with HTTP 200 for Midtrans notification
   * So Notification Engine can mark notification complete early and faster,
   * while the order is still being updated.
   * @return void
   */
  public function doEarlyAckResponse() {
    $this->sendPlainResponseHeaders( 200 );
    ob_start();
    echo 'Notification Received';
    header( 'Connection: close' );
    header( 'Content-Length: ' . ob_get_length() );
    ob_end_flush();
    @ob_flush();
    flush();
    if ( function_exists( 'fastcgi_finish_request' ) ) {
      fastcgi_finish_request();
    }
  }

  /**
   * Redirect transacting-user to the finish url set when they were checking out
   * if they were the authorized transacting-user, they will have the finish_url on cookie
   * @TAG: finish_url_user_cookies
   */
  public function checkAndRedirectUserToFinishUrl(){
    $finish_url = false;
    if( isset( $_COOKIE['wc_midtrans_last_order_finish_url'] ) && is_string( $_COOKIE['wc_midtrans_last_order_finish_url'] ) ){
      // cookie value is user input, only allow redirect to this site (prevent open redirect)
      $finish_url = wp_validate_redirect(
        esc_url_raw( wp_unslash( $_COOKIE['wc_midtrans_last_order_finish_url'] ) ), false );
    }
    if( $finish_url ){
      // authorized transacting-user
      wp_safe_redirect( $finish_url );
      exit;
    }
    // else, unauthorized user, redirect to shop homepage by default.
    $this->redirectToShopPage();
  }

  /**
   * Redirect to shop page, then stop the request
   * @return void
   */
  public function redirectToShopPage(){
    wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
    exit;
  }

  /**
   * getPluginOptions
   * @param  string $plugin_id plugin id of the paid order
   * @return array  plugin options
   */
  public function getPluginOptions($plugin_id = 'midtrans'){
    // Get current plugin options
    $plugin_options = get_option( 'woocommerce_' . $plugin_id . '_settings' );
    return is_array( $plugin_options ) ? $plugin_options : array();
  }

  /**
   * Get id of the gateway whose config (Server Key, etc) should be used for an order
   * @param  WC_Order|bool $wcorder
   * @return string plugin id
   */
  public function getPluginIdOfOrder( $wcorder ){
    $plugin_id = $wcorder ? (string) $wcorder->get_payment_method() : '';
    if( strpos( $plugin_id, 'midtrans' ) !== 0 || strpos( $plugin_id, 'midtrans_sub' ) !== false ){
      // for sub separated gateway buttons (and unknown gateway), use main gateway plugin id instead
      $plugin_id = 'midtrans';
    }
    return $plugin_id;
  }

  /**
   * Handle HTTP notification (HTTP POST with JSON body) sent by Midtrans.
   * The notification is only processed when:
   * - its `signature_key` is valid (signed using merchant's Server Key), and
   * - the transaction status re-fetched from Midtrans API is successfully retrieved
   * @return void
   */
  public function handleMidtransHttpNotification() {
    $raw_notification = $this->getRawNotification();
    if ( ! $raw_notification
      || empty( $raw_notification['order_id'] ) || ! is_string( $raw_notification['order_id'] )
      || empty( $raw_notification['transaction_id'] ) || ! is_string( $raw_notification['transaction_id'] )
    ) {
      $this->sendPlainResponseAndExit( 400, 'Invalid notification body' );
    }
    $this->raw_notification = $raw_notification;

    // @TAG: order-id-suffix-handling
    $wc_order_id = 
      WC_Midtrans_Utils::get_wc_order_id($raw_notification['order_id']);
    // Get WooCommerce order, and the gateway used to pay it
    $wcorder = wc_get_order( $wc_order_id );
    $plugin_id = $this->getPluginIdOfOrder( $wcorder );

    // Verify the notification really comes from Midtrans.
    // - `valid`: signed with merchant's Signature Key (or Server Key), the body can be trusted.
    // - `invalid`: Signature Key is configured and the signature doesn't match, reject.
    // - `unknown`: can't be verified locally (Signature Key is not configured). The body is not
    //   trusted, only the status fetched from Midtrans API below will be used.
    $signature_state = 'unknown';
    if ( apply_filters( 'midtrans_verify_notification_signature', true, $raw_notification, $plugin_id ) ) {
      $signature_state = WC_Midtrans_API::getNotificationSignatureState( $raw_notification, $plugin_id );
      if ( $signature_state === 'invalid' ) {
        WC_Midtrans_Logger::log( 'Notification rejected, invalid signature_key. order_id: ' . sanitize_text_field( $raw_notification['order_id'] ), 'midtrans-error' );
        $this->sendPlainResponseAndExit( 403, 'Invalid signature' );
      }
    }
    $is_signature_verified = ( $signature_state === 'valid' );
    WC_Midtrans_Logger::log( print_r($raw_notification, true), 'midtrans-notif' );

    // exit if the order id doesn't exist in WooCommerce dashboard
    if ( ! $wcorder ) {
      WC_Midtrans_Logger::log( 'Can\'t find order id ' . sanitize_text_field( $raw_notification['order_id'] ) . ' on WooCommerce dashboard', 'midtrans-error' );
      // respond OK, so the notification will not be retried
      $this->sendPlainResponseAndExit( 200, 'Notification Received' );
    }

    // Get the real transaction status directly from Midtrans API
    try {
      $midtrans_notification = WC_Midtrans_API::getStatusFromMidtransNotif( $plugin_id, $raw_notification );
    } catch ( Exception $e ) {
      WC_Midtrans_Logger::log( 'Fail to get status of notification: ' . $e->getMessage(), 'midtrans-error' );
      // respond with error, so the notification will be retried later by Midtrans
      $this->sendPlainResponseAndExit( 500, 'Fail to verify notification' );
    }
    $status_response = $midtrans_notification->getResponse();
    if ( ! is_object( $status_response ) || ! isset( $status_response->transaction_status )
      || ! in_array( $status_response->status_code, array( 200, 201, 202, 407 ) )
    ) {
      $this->sendPlainResponseAndExit( 200, 'Notification Received' );
    }
    // the status must belong to the transaction that is being notified
    if ( isset( $status_response->transaction_id ) && $status_response->transaction_id !== $raw_notification['transaction_id'] ) {
      WC_Midtrans_Logger::log( 'Notification ignored, transaction_id mismatch. order_id: ' . sanitize_text_field( $raw_notification['order_id'] ), 'midtrans-error' );
      $this->sendPlainResponseAndExit( 200, 'Notification Received' );
    }
    if ( $is_signature_verified ) {
      // BI-SNAP (e.g: VA of Danamon, BSI, Seabank, Saqu) & DANA return `transaction_id` on `order_id`
      // field of get-status response. `order_id` of the notification body is signed by Midtrans
      // (part of `signature_key`), so it is safe to be used as the source of truth.
      $status_response->order_id = $raw_notification['order_id'];
    }
    else if ( isset( $status_response->order_id ) && $status_response->order_id === $raw_notification['order_id'] ) {
      // Midtrans API confirms this transaction belongs to the order_id mentioned on the notification body
    }
    else if ( WC_Midtrans_API::isTransactionOfOrderSnapToken( $wcorder, $raw_notification['order_id'], $raw_notification['transaction_id'], $plugin_id ) ) {
      // BI-SNAP & DANA: Snap API confirms this transaction was made using the Snap token of this order
      $status_response->order_id = $raw_notification['order_id'];
    }
    else {
      // The signature can't be verified, and Midtrans API doesn't confirm that this transaction
      // belongs to the order_id mentioned on the notification body. Never trust the body in this case.
      WC_Midtrans_Logger::log( 'Notification rejected, order_id ' . sanitize_text_field( $raw_notification['order_id'] ) . ' can\'t be verified. If you are using DANA or bank transfer of Danamon/BSI/SeaBank/Saqu, please fill the "Signature Key" on Midtrans plugin configuration page', 'midtrans-error' );
      $this->sendPlainResponseAndExit( 403, 'Unverified notification' );
    }
    if ( empty( $status_response->order_id ) || ! is_string( $status_response->order_id ) ) {
      $this->sendPlainResponseAndExit( 200, 'Notification Received' );
    }
    if ( ! isset( $status_response->payment_type ) && isset( $raw_notification['payment_type'] ) && is_string( $raw_notification['payment_type'] ) ) {
      $status_response->payment_type = $raw_notification['payment_type'];
    }
    // some payment channels return upper-cased status (e.g: `PENDING`)
    $status_response->transaction_status = strtolower( (string) $status_response->transaction_status );
    if ( isset( $status_response->fraud_status ) ) {
      $status_response->fraud_status = strtolower( (string) $status_response->fraud_status );
    }

    // @TAG: order-id-suffix-handling
    $order_id = 
      WC_Midtrans_Utils::get_wc_order_id($status_response->order_id);
    if ( ! wc_get_order( $order_id ) ) {
      WC_Midtrans_Logger::log( 'Can\'t find order id ' . sanitize_text_field( $order_id ) . ' on WooCommerce dashboard', 'midtrans-error' );
      $this->sendPlainResponseAndExit( 200, 'Notification Received' );
    }

    // Notification is valid, respond early then update the order
    $this->doEarlyAckResponse();
    // @TODO: rename this hook to use snake_case format
    do_action( "midtrans-handle-valid-notification", $midtrans_notification, $plugin_id );
    exit;
  }

  /**
   * Called by hook function when HTTP notification / API call received
   * Handle Midtrans payment notification
   */
  public function handleMidtransNotificationRequest() {
    @ob_clean();
    global $woocommerce;

    $sanitized = [];
    $sanitized['order_id'] = 
      isset($_GET['order_id']) && is_string($_GET['order_id'])? sanitize_text_field(wp_unslash($_GET['order_id'])): null;
    $sanitized['id'] = 
      isset($_GET['id']) && is_string($_GET['id'])? sanitize_text_field(wp_unslash($_GET['id'])): null;
    $sanitizedPost = [];
    $sanitizedPost['id'] = 
      isset($_POST['id']) && is_string($_POST['id'])? sanitize_text_field(wp_unslash($_POST['id'])): null;
    $sanitizedPost['response'] = 
      isset($_POST['response']) && is_string($_POST['response'])? sanitize_text_field(wp_unslash($_POST['response'])): null;

    // @TAG: order-id-suffix-handling
    $sanitized['order_id'] = 
      WC_Midtrans_Utils::check_and_restore_original_order_id($sanitized['order_id']);

    // check whether the request is POST or GET, 
    // @TODO: refactor this conditions, this doesn't quite represent conditions for a POST request
    if(empty($sanitized['order_id']) && empty($sanitizedPost['id']) && empty($sanitized['id']) && empty($sanitizedPost['response'])) { 
      $request_method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
      if ( $request_method !== 'POST' ) {
        // Reject non HTTP POST request, e.g: opened using browser
        $this->sendPlainResponseAndExit( 200, 'This endpoint is for Midtrans notification URL (HTTP POST). This message will be shown if opened using browser (HTTP GET). You can copy this current URL on your browser address bar and paste it to: "Midtrans Dashboard > Settings > Configuration > Notification Url". This will allow your WooCommerce to receive Midtrans payment status, which will auto sync the payment status.' );
      }
      // Request is POST, verify the notification, then update the payment status
      $this->handleMidtransHttpNotification();
      exit;
    }
    else { 
      // The request == GET, this will handle redirect url from Snap finish OR failed, proceed to redirect to WooCommerce's order complete/failed page
      // Note: values from these params are untrusted (controlled by user), so those are only
      // used to decide redirection, never to alter any order status
      $sanitized['transaction_status'] = 
        isset($_GET['transaction_status']) && is_string($_GET['transaction_status'])? sanitize_text_field(wp_unslash($_GET['transaction_status'])): null;
      $sanitized['status_code'] = 
        isset($_GET['status_code']) && is_string($_GET['status_code'])? absint($_GET['status_code']): null;
      $sanitized['wc-api'] = 
        isset($_GET['wc-api']) && is_string($_GET['wc-api'])? sanitize_text_field(wp_unslash($_GET['wc-api'])): null;

      // if capture/settlement, redirect to order received page
      if( !empty($sanitized['order_id']) && !empty($sanitized['status_code']) && $sanitized['status_code'] <= 200)  {
        $this->checkAndRedirectUserToFinishUrl();
      } 
      // if or pending/challenge
      else if( !empty($sanitized['order_id']) && !empty($sanitized['transaction_status']) && $sanitized['status_code'] == 201)  {
        $order = wc_get_order( $sanitized['order_id'] );
        if( $order ){
          $plugin_options = $this->getPluginOptions( $order->get_payment_method() );
          if( array_key_exists('ignore_pending_status',$plugin_options)
            && $plugin_options['ignore_pending_status'] == 'yes'
          ){
            $this->redirectToShopPage();
          }
        }
        $this->checkAndRedirectUserToFinishUrl();
      } 
      //if deny, redirect to order checkout page again
      else if( !empty($sanitized['order_id']) && !empty($sanitized['transaction_status']) && $sanitized['status_code'] >= 202){
        $this->redirectToShopPage();
      } 
      // if customer click "back" button, redirect to checkout page again
      else if( !empty($sanitized['order_id']) && empty($sanitized['transaction_status'])){ 
        $this->redirectToShopPage();
      // if customer redirected from async payment with POST `response` (CIMB clicks, etc)
      } else if ( !empty($sanitizedPost['response']) ){ 
        $responses = json_decode( $sanitizedPost['response'], true);
        // if async payment paid
        if ( is_array($responses) && isset($responses['status_code']) && $responses['status_code'] == 200) { 
          $this->checkAndRedirectUserToFinishUrl();
        } 
        // if async payment not paid
        $this->redirectToShopPage();
      // if customer redirected from async payment with GET `id` (BCA klikpay, etc)
      } else if (!empty($sanitized['id']) || (!empty($sanitized['wc-api']) && strlen($sanitized['wc-api']) >= 25) ){
        // Workaround if id query string is malformed, manual substring
        if (!empty($sanitized['wc-api']) && strlen($sanitized['wc-api']) >= 25) {
          $id = str_replace("WC_Gateway_Midtrans?id=", "", $sanitized['wc-api']);
        }
        // else if id query string format is correct
        else {
          $id = $sanitized['id'];
        }
        // `id` is Midtrans transaction_id, only allow the expected format
        if ( ! WC_Midtrans_Utils::is_valid_midtrans_id( $id ) ) {
          $this->redirectToShopPage();
        }
        // Get the order (and its gateway) which have this transaction_id
        $order = WC_Midtrans_Utils::get_order_by_transaction_id( $id );
        try {
          $midtrans_notification = WC_Midtrans_API::getMidtransStatus( $id, $this->getPluginIdOfOrder( $order ) );
        } catch (Exception $e) {
          WC_Midtrans_Logger::log( 'Fail to get status on finish redirect: ' . $e->getMessage(), 'midtrans-error' );
          $this->redirectToShopPage();
        }
        // if async payment paid
        if (isset($midtrans_notification->transaction_status) && $midtrans_notification->transaction_status == 'settlement'){
          $this->checkAndRedirectUserToFinishUrl();
        } 
        // if async payment not paid
        $this->redirectToShopPage();
      } 
      // if unhandled case, fallback, redirect to home
      else {
        $this->redirectToShopPage();
      }
    }
  }

  /**
   * Handle Midtrans Notification Object, after payment status changes on Midtrans
   * Will update WC payment status accordingly
   * @param  [Object] $midtrans_notification Object representation of Midtrans JSON
   * notification
   * @return void
   */
  public function handleMidtransValidNotificationRequest( $midtrans_notification, $plugin_id = 'midtrans' ) {
    global $woocommerce;
    // @TAG: order-id-suffix-handling
    $order_id = WC_Midtrans_Utils::get_wc_order_id($midtrans_notification->order_id);
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
      return;
    }
    // values below are stored & displayed on WP admin panel, make sure those are plain text
    $payment_type = sanitize_text_field( (string) $midtrans_notification->payment_type );
    $transaction_status = sanitize_text_field( (string) $midtrans_notification->transaction_status );
    $transaction_id = sanitize_text_field( (string) $midtrans_notification->transaction_id );
    $order->add_order_note(__('Midtrans HTTP notification received: '.$transaction_status.'. Midtrans-'.$payment_type,'midtrans-woocommerce'));

    $order->update_meta_data('_mt_payment_type',$payment_type);
    $order->save();
    // Order may have more than 1 Midtrans transaction (e.g: customer retry the payment, with
    // suffixed order_id). Status of the abandoned one should not alter the order that is already paid.
    $is_paid_by_other_transaction = $order->is_paid()
      && $order->get_transaction_id() && $order->get_transaction_id() !== $transaction_id;
    // allow merchant-defined custom action function to perform action on $order upon notif handling
    do_action( 'midtrans_on_notification_received', $order, $midtrans_notification );
    
    /**
     * @TODO: maybe refactor this if-else branch, store the condition into variable e.g: 
     * $is_payment_success = this or that etc. 
     * $is_payment_failed, $is_payment_pending, $is_payment_refund.
     * So the if-else branch only need to check from those var, instead of directly checking the condition.
     */
    if ( $midtrans_notification->transaction_status == 'settlement'
      || ($midtrans_notification->transaction_status == 'capture' && $midtrans_notification->fraud_status == 'accept') ) {
      // success scenario of payment paid

      // Paid amount must not be less than the amount requested when the transaction was created
      if ( apply_filters( 'midtrans_validate_notification_amount', true, $order, $midtrans_notification )
        && WC_Midtrans_API::isPaidAmountLessThanExpected( $order, $midtrans_notification->order_id, $midtrans_notification->gross_amount )
      ) {
        $paid_amount = sanitize_text_field( (string) $midtrans_notification->gross_amount );
        WC_Midtrans_Logger::log( 'Paid amount ' . $paid_amount . ' is less than expected, order id ' . $order_id . ' need manual review', 'midtrans-error' );
        if ( ! $order->is_paid() ) {
          $order->update_status('on-hold',__('Midtrans payment need manual review, paid amount ('.$paid_amount.') is less than the order amount: Midtrans-'.$payment_type,'midtrans-woocommerce'));
        } else {
          $order->add_order_note(__('Midtrans payment ignored, paid amount ('.$paid_amount.') is less than the order amount: Midtrans-'.$payment_type,'midtrans-woocommerce'));
        }
        exit;
      }

      // Procces subscription transaction if contains subsctription for card transaction
      if( $midtrans_notification->transaction_status == 'capture' && class_exists( 'WC_Subscriptions' ) ){
        $this->checkAndHandleWCSubscriptionTxnNotif( $midtrans_notification, $order );
      }
      $order->payment_complete($transaction_id);
      $order->add_order_note(__('Midtrans payment completed: '.$transaction_status.'. Midtrans-'.$payment_type,'midtrans-woocommerce'));
      // allow merchant-defined custom action function to perform action on $order
      do_action( 'midtrans_after_notification_payment_complete', 
        $order, $midtrans_notification );

      // apply custom order status mapping coming from custom_payment_complete_status config value
      $plugin_options = $this->getPluginOptions($plugin_id);
      if( array_key_exists('custom_payment_complete_status',$plugin_options)
          && $plugin_options['custom_payment_complete_status'] !== 'default'
        ){
        $order->update_status(
          $plugin_options['custom_payment_complete_status'],
          __('Status auto-updated via custom status mapping config: Midtrans-'.$payment_type,'midtrans-woocommerce')
        );
      }
    }
    else if ($midtrans_notification->transaction_status == 'capture' && $midtrans_notification->fraud_status == 'challenge') {
      $order->update_status('on-hold',__('Challanged payment: Midtrans-'.$payment_type,'midtrans-woocommerce'));
    }
    else if ($is_paid_by_other_transaction && in_array($midtrans_notification->transaction_status, array('cancel', 'expire', 'pending'))) {
      // do nothing, the order is already paid by another transaction
      $order->add_order_note(__('Midtrans notification ignored, order is already paid by another transaction: '.$transaction_status.'. Midtrans-'.$payment_type,'midtrans-woocommerce'));
    }
    else if ($midtrans_notification->transaction_status == 'cancel') {
      $order->update_status('cancelled',__('Cancelled payment: Midtrans-'.$payment_type,'midtrans-woocommerce'));
    }
    else if ($midtrans_notification->transaction_status == 'expire') {
      if ($midtrans_notification->payment_type == 'credit_card'){
        // do nothing on card status expire (happen if 3DS abandoned), allow payment retries
        // @NOTE: but it may means card txn, will never notif-based cancel-order trigger to WC.
        // Fortunately WC seems to have auto cancel-order built in.
      } else {
        $order->update_status('cancelled',__('Expired payment: Midtrans-'.$payment_type,'midtrans-woocommerce'));
      }
    }
    else if ($midtrans_notification->transaction_status == 'deny') {
      // do nothing on deny, allow payment retries
      // $order->update_status('failed',__('Denied payment: Midtrans-'.$payment_type,'midtrans-woocommerce'));
    }
    else if ($midtrans_notification->transaction_status == 'pending') {
      // Store snap token & snap redirect url to $order metadata
      $order->update_meta_data('_mt_payment_transaction_id',$transaction_id);
      $order->save();

      $plugin_options = $this->getPluginOptions($plugin_id);
      if( array_key_exists('ignore_pending_status',$plugin_options)
          && $plugin_options['ignore_pending_status'] == 'yes'
        ){
        exit;
      }
      $order->update_status('on-hold',__('Awaiting payment: Midtrans-'.$payment_type,'midtrans-woocommerce'));
    }
    else if ($midtrans_notification->transaction_status == 'refund' || $midtrans_notification->transaction_status == 'partial_refund') {
      $refund_request = $this->validateRefundNotif( $midtrans_notification );
      if ( ! $refund_request ) exit;
      try {
        do_action( "create-refund-request", $midtrans_notification->order_id, $refund_request->refund_amount, $refund_request->reason, $midtrans_notification->transaction_status == 'refund' ? true : false );
        // Create refund note
        $order->add_order_note(sprintf(__('Refunded payment: Midtrans-' . $payment_type . ' Refunded %1$s - Refund ID: %2$s - Reason: %3$s', 'woocommerce-midtrans'), wc_price($refund_request->refund_amount), esc_html($refund_request->refund_key), esc_html($refund_request->reason)));
      } catch (Exception $e) {
          WC_Midtrans_Logger::log( $e->getMessage(), 'midtrans-error' );
      }
    }
    exit;
  }

  /**
   * Validate Midtrans Refund Notification Object
   * @param  [Object] $midtrans_notification Object representation of Midtrans Refund JSON notification
   * @return object||bool
   */
  public function validateRefundNotif( $midtrans_notification ) {
    if ( ! is_array( $midtrans_notification->refunds ) || count( $midtrans_notification->refunds ) < 1 ) {
      return false;
    }
    // Get the raw post notification
    $raw_notification = is_array( $this->raw_notification )
      ? $this->raw_notification
      : $this->getRawNotification();
    // Fetch last array index
    $lastArrayIndex = count($midtrans_notification->refunds) - 1;
    // Do not process if the notif contain 'bank_confirmed_at'
    if (isset($raw_notification['refunds'][$lastArrayIndex]['bank_confirmed_at'])) {
      return false;
    }

    $refund_request = $midtrans_notification->refunds[$lastArrayIndex];
    if ( ! is_object( $refund_request ) || empty( $refund_request->refund_key ) || ! isset( $refund_request->refund_amount ) ) {
      return false;
    }
    // values below are stored & displayed on WP admin panel, make sure those are plain text
    $refund_request->refund_key = sanitize_text_field( (string) $refund_request->refund_key );
    $refund_request->reason = isset( $refund_request->reason ) ? sanitize_text_field( (string) $refund_request->reason ) : '';
    // @TAG: order-id-suffix-handling
    $order_id = 
      WC_Midtrans_Utils::get_wc_order_id($midtrans_notification->order_id);
    // Validate the refund doesn't charge twice by the refund last index
    $order_notes = wc_get_order_notes(array('order_id' => $order_id));
    foreach($order_notes as $value) {
      if (strpos($value->content, $refund_request->refund_key ) !== false) {
        return false;
      }
    }
    return $refund_request;
  }

  /**
   * Process subscription transaction if contains one of those
   * 
   * @param [Object] $midtrans_notification Object representation of Midtrans JSON notification
   * @param WC_Order $order 
   * @return void
   */
  public function checkAndHandleWCSubscriptionTxnNotif( $midtrans_notification, $order ) {
    // @TAG: order-id-suffix-handling
    $order_id = 
      WC_Midtrans_Utils::get_wc_order_id($midtrans_notification->order_id);
    // Process if this is a subscription transaction
    if ( wcs_order_contains_subscription( $order_id ) || wcs_is_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) {
      // if not subscription and wc status pending, don't process (because that's a recurring transaction)
      if ( wcs_order_contains_renewal( $order_id) && $order->get_status() == 'pending' ) {
        return false;
      }
        $subscriptions = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'any' ) );
        foreach ( $subscriptions as $subscription ) {
            // Store card token to meta if customer choose save card on previous payment
            if ($midtrans_notification->saved_token_id ) {
              $subscription->update_meta_data('_mt_subscription_card_token',sanitize_text_field((string) $midtrans_notification->saved_token_id));
              $subscription->save();
            }
            // Customer didn't choose save card option on previous payment
            else {
              $subscription->add_order_note( __( 'Customer didn\'t tick <b>Save Card Info</b>. <br>The next payment on ' . $subscription->get_date('next_payment', 'site') . ' will fail.', 'midtrans-woocommerce'), 1 );
              $order->add_order_note( __('Customer didn\'t tick <b>Save Card Info</b>, next payment will fail', 'midtrans-woocommerce'), 1 );
              $subscription->update_meta_data('_mt_subscription_card_token',$midtrans_notification->saved_token_id);
              $subscription->save();
            }
        }
    }
  }

}