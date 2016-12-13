<?php

namespace Drupal\commerce_atos\Plugin\Commerce\PaymentGateway;

use GuzzleHttp\Client;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayBase;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsStoredPaymentMethodsInterface;
use Drupal\Core\Form\FormStateInterface;
use Sips\PaymentRequest;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines the SIPS Payment for Offsite purchases.
 *
 * @CommercePaymentGateway(
 *   id = "sips_payment",
 *   label = "SIPS Payment (Offsite)",
 *   display_label = "SIPS Payment (Offsite)",
 *    forms = {
 *     "add-payment" = "Drupal\commerce_atos\Plugin\Form\SIPSPaymentAddForm",
 *     "add-payment-method" = "Drupal\commerce_atos\Plugin\Form\SIPSPaymentMethodAddForm",
 *     "sips-authorize-capture-payment" = "Drupal\commerce_atos\Plugin\Form\SIPSAuthorizeCaptureForm",
 *   },
 *   payment_method_types = {"sips"},
 *   modes = {"TEST", "SIMU", "PRODUCTION"}
 * )
 */
class SIPSPaymentGateway extends PaymentGatewayBase implements SupportsStoredPaymentMethodsInterface {

  /**
   * Matching indexes with payment modes URLs.
   */
  const MODES = [
    0 => PaymentRequest::TEST,
    1 => PaymentRequest::SIMU,
    2 => PaymentRequest::PRODUCTION,
  ];

  /**
   * An instance of the guzzle http client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * SIPSPaymentGateway constructor.
   *
   * @param array $configuration
   *   The configuration of the plugin.
   * @param string $plugin_id
   *   The id of the plugin.
   * @param array $plugin_definition
   *   The definition of the plugin.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\commerce_payment\PaymentTypeManager $payment_type_manager
   *   The payment type manager.
   * @param \Drupal\commerce_payment\PaymentMethodTypeManager $payment_method_type_manager
   *   The paymethod method types manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, PaymentTypeManager $payment_type_manager, PaymentMethodTypeManager $payment_method_type_manager, Client $guzzle_client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager);

    $this->httpClient = $guzzle_client;

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
      $container->get('http_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'sips_interface_version' => '',
      'sips_passphrase' => '',
      'sips_merchant_id' => '',
      'sips_key_version' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['sips_interface_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Interface version'),
      '#default_value' => $this->configuration['sips_interface_version'],
      '#required' => TRUE,
    ];

    $form['sips_passphrase'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Passphrase'),
      '#default_value' => $this->configuration['sips_passphrase'],
      '#required' => TRUE,
    ];

    $form['sips_merchant_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Merchant ID'),
      '#default_value' => $this->configuration['sips_merchant_id'],
      '#required' => TRUE,
    ];

    $form['sips_key_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Key version'),
      '#default_value' => $this->configuration['sips_key_version'],
      '#required' => TRUE,
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
      $this->configuration['sips_interface_version'] = $values['sips_interface_version'];
      $this->configuration['sips_passphrase'] = $values['sips_passphrase'];
      $this->configuration['sips_merchant_id'] = $values['sips_merchant_id'];
      $this->configuration['sips_key_version'] = $values['sips_key_version'];
    }
  }

  /**
   * Creates a payment method with the given payment details.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   * @param array $payment_details
   *   The gateway-specific payment details.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   Thrown when the transaction fails for any reason.
   */
  public function createPaymentMethod(PaymentMethodInterface $payment_method, array $payment_details) {
    $required_keys = [
      // The expected keys are payment gateway specific and usually match
      // the PaymentMethodAddForm form elements. They are expected to be valid.
      'payment_option',
    ];
    foreach ($required_keys as $required_key) {
      if (empty($payment_details[$required_key])) {
        throw new \InvalidArgumentException(sprintf('$payment_details must contain the %s key.', $required_key));
      }
    }

    // Perform the create request here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
    // You might need to do different API requests based on whether the
    // payment method is reusable: $payment_method->isReusable().
    // Non-reusable payment methods usually have an expiration timestamp.
    $payment_method->sips_payment_option = $payment_details['payment_option'];

    $payment_method->setReusable(FALSE);
    $payment_method->save();

  }

  /**
   * Deletes the given payment method.
   *
   * Both the entity and the remote record are deleted.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method
   *   The payment method.
   *
   * @throws \Drupal\commerce_payment\Exception\PaymentGatewayException
   *   Thrown when the transaction fails for any reason.
   */
  public function deletePaymentMethod(PaymentMethodInterface $payment_method) {
    // Delete the remote record here, throw an exception if it fails.
    // See \Drupal\commerce_payment\Exception for the available exceptions.
    // Delete the local entity.
    $payment_method->delete();
  }

}
