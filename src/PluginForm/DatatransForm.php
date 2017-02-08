<?php

namespace Drupal\commerce_datatrans\PluginForm;

use Drupal\commerce_datatrans\DatatransHelper;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\currency\Entity\Currency;

/**
 * Provides a checkout form for the Datatrans gateway.
 */
class DatatransForm extends PaymentOffsiteForm {

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;

    // We need the payment_id.
    if ($payment->isNew()) {
      $payment->save();
    }

    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $gateway */
    $gateway = $payment->getPaymentGateway()->getPlugin();
    $gateway_config = $gateway->getConfiguration();

    $data = [
      'merchantId' => $gateway_config['merchant_id'],
      'amount' => $payment->getAmount()->getNumber(),
      'currency' => $payment->getAmount()->getCurrencyCode(),
      'refno' => $payment->id(),
      'sign' => NULL,
      'successUrl' => Url::fromRoute('commerce_datatrans.gateway_success', ['payment' => $payment->id()], ['absolute' => TRUE])->toString(),
      'errorUrl' => Url::fromRoute('commerce_datatrans.gateway_error', ['payment' => $payment->id()], ['absolute' => TRUE])->toString(),
      'cancelUrl' => Url::fromRoute('commerce_datatrans.gateway_cancel', ['payment' => $payment->id()], ['absolute' => TRUE])->toString(),
      'security_level' => $gateway_config['security_level'],
      'datatrans_key' => DatatransHelper::generateDatatransKey($payment),
    ];

    return $this->buildRedirectForm($form, $form_state, $gateway_config['service_url'], $data, static::REDIRECT_POST);
  }

}
