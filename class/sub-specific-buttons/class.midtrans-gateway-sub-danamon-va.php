<?php
if (! defined('ABSPATH')) { exit; }
/**
 * Class for each sub separated gateway buttons extending Abstract "Sub" class
 */
class WC_Gateway_Midtrans_Sub_Danamon_VA extends WC_Gateway_Midtrans_Abstract_Sub {
  function __construct() {
    // used as plugin id
    $this->id = 'midtrans_sub_danamon_va';
    // used as Snap enabled_payments params.
    $this->sub_payment_method_params = ['danamon_va'];
    // used to display icons on customer side's payment buttons.
    $this->sub_payment_method_image_file_names_str_final = 'other_va_1.png';

    parent::__construct();
  }

  public function pluginTitle() {
    return "Midtrans Specific: Bank Transfer Danamon VA";
  }
  public function getSettingsDescription() {
    return "Separated payment buttons for this specific the payment methods with its own icons";
  }
  protected function getDefaultTitle () {
    return __('Bank Transfer - Danamon', 'midtrans-woocommerce');
  }
  protected function getDefaultDescription () {
    return __('', 'midtrans-woocommerce');
  }
}
