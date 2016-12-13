<?php

namespace Drupal\commerce_atos\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_payment\Plugin\Commerce\CheckoutPane\PaymentInformation;
use Drupal\Core\Form\FormStateInterface;

/**
 * SIPS Payment information checkout pane.
 *
 * @CommerceCheckoutPane(
 *   id = "sips_payment_information",
 *   label = @Translation("SIPS Payment information"),
 *   default_step = "order_information",
 *   wrapper_element = "fieldset",
 * )
 */
class SIPSPaymentInformation extends PaymentInformation {

  /**
   * Sets the payment gateway and payment method to the order.
   *
   * This differs from the standard PaymentInformation as it does not touch the
   * order BillingProfile.
   *
   * @param array $pane_form
   *   The form array for the pane.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $complete_form
   *   The complete form (including all panes).
   */
  public function submitPaneForm(array &$pane_form, FormStateInterface $form_state, array &$complete_form) {
    $values = $form_state->getValue($pane_form['#parents']);
    if (is_numeric($values['payment_method'])) {
      /** @var \Drupal\commerce_payment\PaymentMethodStorageInterface $payment_method_storage */
      $payment_method_storage = $this->entityTypeManager->getStorage('commerce_payment_method');
      $payment_method = $payment_method_storage->load($values['payment_method']);
    }
    else {
      $payment_method = $values['add_payment_method'];
    }

    /** @var \Drupal\commerce_payment\Entity\PaymentMethodInterface $payment_method */
    $this->order->payment_gateway = $payment_method->getPaymentGateway();
    $this->order->payment_method = $payment_method;
  }

  /**
   * {@inheritdoc}
   */
  public function isVisible() {
    return !$this->order->getTotalPrice()->isZero();
  }

}
