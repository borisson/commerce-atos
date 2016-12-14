<?php

namespace Drupal\commerce_atos\Plugin\Form;

use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PluginForm\PaymentGatewayFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * SIPS Payment form.
 */
class SIPSPaymentMethodAddForm extends PaymentGatewayFormBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getErrorElement(array $form, FormStateInterface $form_state) {
    return $form['payment_details'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = $this->entity;

    $form['#attached']['library'][] = 'commerce_payment/payment_method_form';
    $form['#tree'] = TRUE;
    $form['payment_details'] = [
      '#parents' => array_merge($form['#parents'], ['payment_details']),
      '#type' => 'container',
      '#payment_method_type' => $payment_method->bundle(),
    ];

    // Create an array of available methods.
    $methods = [
      'VISA' => $this->t('Visa'),
      'MASTERCARD' => $this->t('MasterCard'),
      'MAESTRO' => $this->t('Maestro'),
    ];

    $options = $this->createOptionsForPaymentMethods($methods);

    $form['payment_details']['payment_option'] = [
      '#type' => 'radios',
      '#prefix' => '<div class="payment-options">',
      '#suffix' => '</div>',
      '#options' => $options,
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    if (in_array('::previousForm', $form_state->getTriggeringElement()['#submit'])) {
      $form_state->clearErrors();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    if (in_array('::previousForm', $form_state->getTriggeringElement()['#submit'])) {
      return TRUE;
    }

    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $payment_method = $this->entity;

    $values = $form_state->getValue($form['#parents']);
    $payment_details_values = $values['payment_details'];

    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsStoredPaymentMethodsInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $this->plugin;

    try {
      $payment_gateway_plugin->createPaymentMethod($payment_method, $payment_details_values);

    }
    catch (PaymentGatewayException $e) {
      \Drupal::logger('commerce_atos')->warning('Exception thrown when creating the payment method: ' . $e->getMessage());
      throw new PaymentGatewayException('We encountered an unexpected error processing your payment method. Please try again later.');
    }

  }

  /**
   * Transform the array of methods into an array of options.
   *
   * @param string[] $methods
   *   An array of methods, keyed by machine name.
   *
   * @return string[]
   *   An array of methods, keyed by machine name.
   */
  protected function createOptionsForPaymentMethods(array $methods) {
    $prefix = '<span class="payment-option-element payment-option-element--';
    $suffix = '</span>';

    // Loop over all methods and transform them into items used in the option
    // list.
    $options = [];
    foreach ($methods as $key => $item) {
      $options[$key] = $prefix . strtolower($key) . '">' . $item . $suffix;
    }
    return $options;
  }

}
