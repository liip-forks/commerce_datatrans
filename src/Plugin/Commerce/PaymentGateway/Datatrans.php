<?php

namespace Drupal\commerce_datatrans\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides the Datatrans payment gateway.
 *
 * @CommercePaymentGateway(
 *   id = "datatrans",
 *   label = "Datatrans",
 *   display_label = "Datatrans",
 *    forms = {
 *     "offsite-payment" = "Drupal\commerce_datatrans\PluginForm\DatatransForm",
 *   },
 *   payment_method_types = {"credit_card"},
 *   credit_card_types = {
 *     "VIS", "ECA", "AMX", "BPY", "DIN", "DIS", "DEA", "DIB", "DII", "DNK",
 *     "DVI", "ELV", "ESY", "JCB", "JEL", "MAU", "MDP", "MFA", "MFG", "MFX",
 *     "MMS", "MNB", "MYO", "PAP", "PEF", "PFC", "PSC", "PYL", "PYO", "REK",
 *     "SWB", "TWI", "MPW", "ACC", "INT", "PPA", "GPA", "GEP"
 *   },
 * )
 */
class Datatrans extends OffsitePaymentGatewayBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
        'merchant_id' => '',
        'service_url' => ' https://pilot.datatrans.biz/upp/jsp/upStart.jsp',
        'req_type' => 'CAA',
        'use_alias' => FALSE,
        'security_level' => 2,
        'sign' => '',
        'hmac_key' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['merchant_id'] = [
      '#type' => 'textfield',
      '#title' => t('Merchant-ID'),
      '#default_value' => $this->configuration['merchant_id'],
      '#required' => TRUE,
    ];

    $form['service_url'] = [
      '#type' => 'textfield',
      '#title' => t('Service URL'),
      '#default_value' => $this->configuration['service_url'],
      '#required' => TRUE,
    ];

    $form['req_type'] = [
      '#type' => 'select',
      '#title' => t('Request Type'),
      '#options' => [
        'NOA' => t('Authorization only'),
        'CAA' => t('Authorization with immediate settlement'),
//        'conditional' => t('Authorization with conditional settlement'),
        'ignore' => t('According to the setting in the Web Admin Tool'),
      ],
      '#default_value' => $this->configuration['req_type'],
    ];

    $form['use_alias'] = [
      '#type' => 'checkbox',
      '#title' => 'Use Alias',
      '#default_value' => $this->configuration['use_alias'],
      '#description' => t('Enable this option to always request an alias from datatrans. This is used for recurring payments and should be disabled if not necessary. If the response does not provide an alias, the payment will not be settled (or refunded, in case it was settled immediately) and the payment needs to be repeated.'),
    ];

    $url = Url::fromUri('https://pilot.datatrans.biz/showcase/doc/Technical_Implementation_Guide.pdf', ['external' => TRUE])->toString();
    $form['security'] = [
      '#type' => 'fieldset',
      '#title' => t('Security Settings'),
      '#collapsible' => FALSE,
      '#collapsed' => FALSE,
      '#description' => t('You should not work with anything else than security level 2 on a productive system. Without the HMAC key there is no way to check whether the data really comes from Datatrans. You can find more details about the security levels in your Datatrans account at UPP ADMINISTRATION -> Security. Or check the technical information in the <a href=":url">Technical_Implementation_Guide</a>', [':url' => $url]),
    ];

    $form['security']['security_level'] = [
      '#type' => 'select',
      '#title' => t('Security Level'),
      '#options' => [
        '0' => t('Level 0. No additional security element will be send with payment messages. (not recommended)'),
        '1' => t('Level 1. An additional Merchant-Identification will be send with payment messages'),
        '2' => t('Level 2. Important parameters will be digitally signed (HMAC-MD5) and sent with payment messages'),
      ],
      '#default_value' => $this->configuration['security_level'],
    ];

    $form['security']['sign'] = [
      '#type' => 'textfield',
      '#title' => t('Merchant control sign'),
      '#default_value' => $this->configuration['sign'],
      '#description' => t('Used for security level 1'),
      '#states' => [
        'visible' => [
          ':input[name="configuration[security][security_level]"]' => ['value' => '1'],
        ],
      ],
    ];

    $form['security']['hmac_key'] = [
      '#type' => 'textfield',
      '#title' => t('HMAC Key'),
      '#default_value' => $this->configuration['hmac_key'],
      '#description' => t('Used for security level 2'),
      '#states' => [
        'visible' => [
          ':input[name="configuration[security][security_level]"]' => ['value' => '2'],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $this->configuration['merchant_id'] = $values['merchant_id'];
      $this->configuration['service_url'] = $values['service_url'];
      $this->configuration['req_type'] = $values['req_type'];
      $this->configuration['use_alias'] = $values['use_alias'];
      $this->configuration['security_level'] = $values['security']['security_level'];
      $this->configuration['sign'] = $values['security']['sign'];
      $this->configuration['hmac_key'] = $values['security']['hmac_key'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function onReturn(OrderInterface $order, Request $request) {
    // @todo Add examples of request validation.
    $payment_storage = $this->entityTypeManager->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'state' => 'authorization',
      'amount' => $order->getTotalPrice(),
      'payment_gateway' => $this->entityId,
      'order_id' => $order->id(),
      'test' => $this->getMode() == 'test',
      'remote_id' => $request->query->get('txn_id'),
      'remote_state' => $request->query->get('payment_status'),
      'authorized' => REQUEST_TIME,
    ]);
    $payment->save();
    drupal_set_message('Payment was processed');
  }

}
