<?php

namespace Drupal\commerce_datatrans\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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
   * Logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a Datatrans object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_payment\PaymentTypeManager $payment_type_manager
   *   The payment type manager.
   * @param \Drupal\commerce_payment\PaymentMethodTypeManager $payment_method_type_manager
   *   The payment method type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, LoggerChannelFactoryInterface $logger_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager);
    $this->logger = $logger_factory->get('commerce_datatrans');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('logger.factory')
    );
  }

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
    $post_data = $request->request->all();

    if ($payment = \Drupal::entityTypeManager()->getStorage('commerce_payment')->load($post_data['refno'])) {
      $currency_code = $payment->getAmount()->getCurrencyCode();
      /** @var \Drupal\commerce_price\Entity\CurrencyInterface $currency */
      $currency = Currency::load($currency_code);

      $a = $post_data['amount'] / pow(10, $currency->getFractionDigits());
      $price = new Price((string) $a, $currency_code);

      // @todo Missing 'state' here.
      $payment->setAmount($price);
      $payment->setRemoteId($post_data['acqAuthorizationCode']);
      $payment->setRemoteState($post_data['status']);
      $payment->setAuthorizedTime(REQUEST_TIME);
//      $payment->save();

    }

    // @todo Missing 'state' here.

    drupal_set_message('Payment was processed');
  }

  /**
   * {@inheritdoc}
   */
  public function onCancel(OrderInterface $order, Request $request) {
    drupal_set_message($this->t('You have canceled checkout at @gateway but may resume the checkout process here when you are ready.', [
      '@gateway' => $this->getDisplayLabel(),
    ]));
  }


  /**
   * {@inheritdoc}
   */
  public function getNotifyUrl() {
    return Url::fromRoute('commerce_payment.notify', [
      'commerce_payment_gateway' => $this->entityId,
    ], ['absolute' => TRUE]);
  }

  /**
   * {@inheritdoc}
   */
  public function onNotify(Request $request) {
    $post_data = $request->request->all();

    if (!isset($post_data['refno'])) {
      return new Response('', 400);
    }

    /** @var \Drupal\commerce_payment\entity\PaymentInterface $payment */
    $payment = $this->entityTypeManager->getStorage('commerce_payment')->load($post_data['refno']);
    if (!$payment) {
      return new Response('', 400);
    }

    switch ($post_data['status']) {
      case 'success':
        $currency_code = $payment->getAmount()->getCurrencyCode();

        /** @var \Drupal\commerce_price\Entity\CurrencyInterface $currency */
        $currency = Currency::load($currency_code);

        $datatrans_amount = $post_data['amount'] / pow(10, $currency->getFractionDigits());
        $price = new Price((string) $datatrans_amount, $currency_code);

        // @todo Missing 'state' here.
        $payment->setAmount($price);
        // Documentation states we should use acqAuthorizationCode but that
        // number is not visible in their ui.
        $payment->setRemoteId($post_data['authorizationCode']);
        $payment->setRemoteState($post_data['status']);
        $payment->setAuthorizedTime(REQUEST_TIME);
        $payment->save();
        break;

      case 'error':
        $this->logger->error('The payment gateway returned the error code %code (%details) for payment %payment_id', [
          '%code' => $post_data['errorCode'],
          '%details' => $post_data['errorDetail'],
          '%payment_id' => $payment->id(),
        ]);
        break;

      case 'cancel':
        $this->logger->info('The user canceled the authorisation process for payment %payment_id', [
          '%payment_id' => $payment->id(),
        ]);
        break;
    }
  }
}
