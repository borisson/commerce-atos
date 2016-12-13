<?php

namespace Drupal\commerce_atos\Plugin\Commerce\PaymentMethodType;

use Drupal\commerce\BundleFieldDefinition;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentMethodType\PaymentMethodTypeBase;

/**
 * Provides the SIPS payment method type.
 *
 * @CommercePaymentMethodType(
 *   id = "sips",
 *   label = @Translation("SIPS account"),
 *   create_label = @Translation("New SIPS account"),
 * )
 */
class SIPS extends PaymentMethodTypeBase {

  /**
   * {@inheritdoc}
   */
  public function buildLabel(PaymentMethodInterface $payment_method) {
    $args = [
      '@name' => $payment_method->sips_payment_option->value,
    ];
    return $this->t('SIPS option: @name', $args);
  }

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions() {
    $fields = parent::buildFieldDefinitions();

    $fields['sips_payment_option'] = BundleFieldDefinition::create('text')
      ->setLabel(t('Payment option'))
      ->setDescription(t('SIPS payment option (paypal, visa, mastercard, etc).'))
      ->setRequired(TRUE);

    $fields['sips_response_code'] = BundleFieldDefinition::create('text')
      ->setLabel(t('Response code'))
      ->setDescription(t('SIPS response code.'));

    $fields['sips_seal'] = BundleFieldDefinition::create('text')
      ->setLabel(t('SIPS seal'))
      ->setDescription(t('Seal used in the purchase.'));

    return $fields;
  }

}
