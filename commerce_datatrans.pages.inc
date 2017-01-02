<?php

/**
 * @file
 * Datatrans menu items callbacks.
 */

/**
 * Custom menu callback: Datatrans payment processing.
 *
 * @param $order_id           Commerce order ID.
 * @param $payment_method_id  Commerce payment method ID.
 * @param $payment_key        URL key used to validate request.
 */
function commerce_datatrans_payment_processing($order_id, $payment_method_id, $payment_key, $error_redirect_url = '') {
  // We are right after (a successful or failed) credit card payment. However,
  // since datatrans payment is not a proper checkout form submission, the
  // order is still in checkout_review status and not in checkout_payment.
  // Since checkout_review is a cart status, hook_commerce_cart_order_refresh()
  // gets invoked when loading the order below. This is definitely not the
  // intended behavior and breaks giftcard coupons by trying to apply them
  // again. So let's trick commerce into thinking that we already have invoked
  // hook_commerce_cart_order_refresh().
  // @see commerce_cart_commerce_order_load()
  // @see commerce_gc_commerce_coupon_final_checkout_validate()
  $refreshed = &drupal_static('commerce_cart_commerce_order_load', array());
  // If for whatever reason the refreshed value is explicitly set to FALSE,
  // let's keep it that way.
  if (!isset($refreshed[$order_id])) {
    $refreshed[$order_id] = TRUE;
  }

  $order     = commerce_order_load($order_id);
  $datatrans = $_POST;

  // First check if the request comes from Datatrans.
  if (isset($datatrans['status']) && $order->data['payment_redirect_key'] == $payment_key) {
    switch ($datatrans['status']) {
      case 'cancel' :
        // Go back to the checkout page we came from.
        drupal_goto(commerce_checkout_order_uri($order));
        break;
      case 'success':
        // Get payment method.
        $payment_method = commerce_payment_method_instance_load($payment_method_id);
        $payment_method['instance_id'] = $payment_method_id;

        if (commerce_datatrans_redirect_form_validate($order, $payment_method)) {
          // Payments can be made in a checkout process or in a terminal (i.e.
          // admin/commerce/orders/%commerce_order/payment). These two cases
          // redirect to different places.
          $state = commerce_order_status_load($order->status)['state'];
          // Transaction save.
          _commerce_datatrans_transaction_save($payment_method, $order, $datatrans, COMMERCE_PAYMENT_STATUS_SUCCESS);

          if ($state == 'checkout') {
            // Send the customer onto the next checkout page.
            commerce_datarans_redirect_pane_next_page($order, t('Customer successfully submitted payment at the payment gateway.'));
            drupal_goto(commerce_checkout_order_uri($order));
          }
          else {
            // Preserve url queries, if any.
            $query = drupal_get_query_parameters();
            $url_options = array('query' => $query);
            // Stay on the order's payments page.
            drupal_goto('admin/commerce/orders/' . $order->order_id . '/payment', $url_options);
          }
        }
        break;
      default:
        // Try to redirect back to the payment site in case of invalid credit
        // card data.
        $error_msg = _commerce_datatrans_map_error_code($datatrans['errorCode']);
        watchdog('commerce_datatrans', $error_msg, $datatrans, WATCHDOG_ERROR);
        drupal_set_message($error_msg, 'error');
        $url = urldecode($error_redirect_url);
        $parsed_url = parse_url($url);
        if (isset($parsed_url['host'])) {
          global $base_url;
          // Redirecting to an external site is insecure.
          if ($base_url != $parsed_url['scheme'] . '://' . $parsed_url['host']) {
            drupal_goto('/');
          }
        }
        drupal_goto($url);
    }
  }
  else {
    drupal_set_message(t('Direct access is not permitted.'), 'error');
  }

  drupal_goto();
}
