<?php 
/**
 * Customizable payment finish page, for internet banking channel, mainly BCA KlikPay
 * Output HTML that display payment result of an internet banking transactions
 * based on order/transaction id found in GET/POST request
 */

// reference: https://www.cloudways.com/blog/creating-custom-page-template-in-wordpress/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Values from request params are untrusted (controlled by user). Those are only used to decide
// which message will be displayed, never printed to the page and never used to alter order status.
$midtrans_notification = new stdClass();
$midtrans_notification->transaction_status = 'not found';
$midtrans_notification->payment_type = '';

try {
	if(isset($_GET['id']) && is_string($_GET['id'])){ // handler for BCA_Klikpay finish redirect
		$trx_id = sanitize_text_field(wp_unslash($_GET['id']));
		if(WC_Midtrans_Utils::is_valid_midtrans_id($trx_id)){
			// Get order from transaction_id meta data
			$order = WC_Midtrans_Utils::get_order_by_transaction_id($trx_id);
			$plugin_id = $order ? $order->get_payment_method() : 'midtrans';
			if(strpos((string) $plugin_id, 'midtrans') !== 0){
				$plugin_id = 'midtrans';
			}
			$status_response = WC_Midtrans_API::getMidtransStatus($trx_id, $plugin_id);
			if(is_object($status_response) && isset($status_response->transaction_status)){
				$midtrans_notification->transaction_status = strtolower((string) $status_response->transaction_status);
				$midtrans_notification->payment_type = 
					isset($status_response->payment_type) ? (string) $status_response->payment_type : '';
			}
		}
	}else if(isset($_POST['response']) && is_string($_POST['response'])){ // handler for CIMB CLICKS finish redirect
		$response = json_decode(sanitize_text_field(wp_unslash($_POST['response'])));
		if(is_object($response) && isset($response->transaction_status) && is_string($response->transaction_status)){
			$midtrans_notification->transaction_status = strtolower($response->transaction_status);
			$midtrans_notification->payment_type = 
				(isset($response->payment_type) && is_string($response->payment_type)) ? $response->payment_type : '';
		}
	}
} catch (Exception $e) {
	WC_Midtrans_Logger::log( 'Failed to do Midtrans Get Status: '.$e->getMessage(), 'midtrans-error' );
	$midtrans_notification->transaction_status = 'not found';
}

get_header(); // WP Header
?>
 
<div id="primary" class="content-area">
    <main id="main" class="site-main" role="main">
        <?php
        // Customize this part
		if($midtrans_notification->transaction_status == 'settlement'){
			// TODO implement what to do when payment success
			?> 
				<h3>Payment Success!</h3>
				<hr>
				<p>We have received your payment, your order is being processed. Thank you!</p>
			<?php
		}else if($midtrans_notification->transaction_status == 'pending'){
			// TODO implement what to do when payment pending
			?> 
				<?php 
					if($midtrans_notification->payment_type == 'bca_klikpay'){
						// BCA Klikpay specific, all non-settlement are considered failure
				?>
					<h3>Payment Failed</h3>
					<hr>
					<p>Sorry, we are unable to receive your payment.</p>
				<?php 
					}else{
						// Other payment, pending is pending
				?>
					<h3>Order is Awaiting Your Payment</h3>
					<hr>
					<p>Please complete the payment as instructed earlier. Thank you!</p>
				<?php 
					}
				?>
			<?php
		}else{
			// TODO implement what to do when payment failed
			?> 
				<h3>Payment Is Not Received</h3>
				<hr>
				<p>Your payment is not yet completed. Please complete your payment or do another checkout. Thank you!</p>
			<?php
		}
        ?>
    </main><!-- .site-main -->
</div><!-- .content-area -->

<!-- WP Sidebar & Footer -->
<?php 
// get_sidebar(); // uncomment this if you need sidebar
get_footer(); 
?>
