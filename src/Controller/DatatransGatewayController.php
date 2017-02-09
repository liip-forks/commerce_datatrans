<?php

namespace Drupal\commerce_datatrans\Controller;

use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\payment_datatrans\DatatransHelper;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * This is a dummy controller for mocking an off-site gateway.
 */
class DatatransGatewayController extends ControllerBase {

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $currentRequest;

  /**
   * Payment storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $paymentStorage;

  /**
   * Constructs a new DummyRedirectController object.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   */
  public function __construct(RequestStack $request_stack, EntityTypeManagerInterface $entity_type_manager) {
    $this->currentRequest = $request_stack->getCurrentRequest();
    $this->paymentStorage = $entity_type_manager->getStorage('commerce_payment');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Page callback for processing successful Datatrans response.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $commerce_payment
   *   The Payment entity type.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   */
  public function success(PaymentInterface $commerce_payment) {
    try {
      // This needs to be checked to match the payment method settings
      // ND being valid with its keys and data.
      $post_data = $request->request->all();

      // Check if payment is pending.
      if ($post_data['datatrans_key'] != DatatransHelper::generateDatatransKey($payment)) {
        throw new \Exception('Invalid datatrans key.');
      }

      // If Datatrans returns error status.
      if ($post_data['status'] == 'error') {
        throw new \Exception(DatatransHelper::mapErrorCode($post_data['errorCode']));
      }

      // This is the internal guaranteed configuration that is to be considered
      // authoritative.
      $plugin_definition = $payment->getPaymentMethod()->getPluginDefinition();

      // Check for invalid security level.
      if (empty($post_data) || $post_data['security_level'] != $plugin_definition['security']['security_level']) {
        throw new \Exception('Invalid security level.');
      }

      // If security level 2 is configured then generate and use a sign.
      if ($plugin_definition['security']['security_level'] == 2) {
        // Generates the sign.

        $sign2 = $this->generateSign2($plugin_definition, $post_data);

        // Check for correct sign.
        if (empty($post_data['sign2']) || $sign2 != $post_data['sign2']) {
          throw new \Exception('Invalid sign.');
        }
      }

      // At that point the transaction is treated to be valid.
      if ($post_data['status'] == 'success') {

        // Store data in the payment configuration.
        $this->setPaymentConfiguration($payment, $post_data);
        /** @var \Drupal\payment_datatrans\Plugin\Payment\Method\DatatransMethod $payment_method */
        $payment_method = $payment->getPaymentMethod();

        // Some providers do not enforce an alias. If the option is enabled and
        // none was provided, the action taken depends on the req_type setting.
        if ($plugin_definition['use_alias'] && empty($post_data['aliasCC'])) {

          if ($plugin_definition['req_type'] == 'conditional') {
            $payment_method->cancelPayment();
            if ($payment->getPaymentStatus()->getPluginId() == $plugin_definition['cancel_status_id']) {
              // The payment was authorized but has now been cancelled. Let the
              // user take action.
              drupal_set_message(t('No alias was provided with the payment. Ensure that the necessary option is selected or use a different payment provider.'), 'warning');
              return $payment->getPaymentType()->getResumeContextResponse()->getResponse();
            }
            else {
              // The payment is still authorized with the service. Let the user
              // complete; a payment with missing recurrence is preferred over
              // no payment.
              \Drupal::logger('datatrans')->warning('Alias is missing but authorization cancelling failed for Payment @id.', ['@id' => $payment->id()]);
            }
          }
          else {
            $payment_method->refundPayment();
            // If refund was successful, redirect the user back, with a message.
            // If refund failed, then there is nothing that can be done, log an
            // error but let the user complete.
            if ($payment->getPaymentStatus()->getPluginId() == $plugin_definition['refund_status_id']) {
              drupal_set_message(t('No alias was provided with the payment. Ensure that the necessary option is selected or use a different payment provider.'), 'warning');
              return $payment->getPaymentType()->getResumeContextResponse()->getResponse();
            }
            else {
              \Drupal::logger('datatrans')->warning('Alias is missing but payment refund failed for Payment @id.', ['@id' => $payment->id()]);
            }
          }
        }

        // To complete the payment when using the conditional request type, make
        // a new request for the settlement.
        if ($plugin_definition['req_type'] == 'conditional') {
          $payment_method->capturePayment();
          if ($payment->getPaymentStatus()->getPluginId() != $plugin_definition['execute_status_id']) {
            throw new \Exception('Authorization succeeded but settlement failed');
          }
        }

        // Save the successful payment.
        return $this->savePayment($payment, isset($plugin_definition['execute_status_id']) ? $plugin_definition['execute_status_id'] : 'payment_success');
      }
      else {
        throw new \Exception('Datatrans communication failure. Invalid data received from Datatrans.');
      }
    }
    catch (\Exception $e) {
      \Drupal::logger('datatrans')->error('Processing failed with exception @e.', array('@e' => $e->getMessage()));
      drupal_set_message(t('Payment processing failed.'), 'error');
      return $this->savePayment($payment);
    }
  }

  /**
   * Generates the sign2 to compare with the datatrans post data.
   *
   * @param array $plugin_definition
   *   Plugin Definition.
   * @param array $post_data
   *   Datatrans Post Data.
   *
   * @return string
   *   The generated sign.
   * @throws \Exception
   */
  public function generateSign2($plugin_definition, $post_data) {
    if ($plugin_definition['security']['hmac_key'] || $plugin_definition['security']['hmac_key_2']) {
      if ($plugin_definition['security']['use_hmac_2']) {
        $key = $plugin_definition['security']['hmac_key_2'];
      }
      else {
        $key = $plugin_definition['security']['hmac_key'];
      }
      return DatatransHelper::generateSign($key, $plugin_definition['merchant_id'], $post_data['uppTransactionId'], $post_data['amount'], $post_data['currency']);
    }

    throw new \Exception('Problem generating sign.');
  }

  /**
   * Saves success/cancelled/failed payment.
   *
   * @param \Drupal\payment\Entity\PaymentInterface $payment
   *  Payment entity.
   * @param string $status
   *  Payment status to set
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */
  public function savePayment(PaymentInterface $payment, $status = 'payment_failed') {
    $payment->setPaymentStatus(\Drupal::service('plugin.manager.payment.status')
      ->createInstance($status));
    $payment->save();
    return $payment->getPaymentType()->getResumeContextResponse()->getResponse();
  }

  /**
   * Sets payments configuration data if present.
   *
   * No validation.
   *
   * @param PaymentInterface $payment
   *   Payment Interface.
   * @param $post_data
   *   Datatrans Post Data.
   */
  public function setPaymentConfiguration(PaymentInterface $payment, $post_data) {
    /** @var \Drupal\payment_datatrans\Plugin\Payment\Method\DatatransMethod $payment_method */
    $payment_method = $payment->getPaymentMethod();
    $customer_details = array(
      'uppCustomerTitle',
      'uppCustomerName',
      'uppCustomerFirstName',
      'uppCustomerLastName',
      'uppCustomerStreet',
      'uppCustomerStreet2',
      'uppCustomerCity',
      'uppCustomerCountry',
      'uppCustomerZipCode',
      'uppCustomerPhone',
      'uppCustomerFax',
      'uppCustomerEmail',
      'uppCustomerGender',
      'uppCustomerBirthDate',
      'uppCustomerLanguage',
      'refno',
      'aliasCC',
      'maskedCC',
      'expy',
      'expm',
      'pmethod',
      'testOnly',
      'authorizationCode',
      'responseCode',
      'uppTransactionId'
    );

    foreach ($customer_details as $key) {
      if (!empty($post_data[$key])) {
        $payment_method->setConfigField($key, $post_data[$key]);
      }
    }
  }

}
