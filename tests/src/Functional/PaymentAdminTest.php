<?php

namespace Drupal\Tests\commerce_atos\Functional;

use Drupal\Tests\commerce_payment\Functional\PaymentAdminTest as CommercePaymentAdminTest;

/**
 * Tests the admin payment UI.
 *
 * @group commerce
 */
class PaymentAdminTest extends CommercePaymentAdminTest {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'commerce_atos',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->paymentGateway = $this->createEntity('commerce_payment_gateway', [
      'id' => 'sips_payment',
      'label' => 'Atos',
      'plugin' => 'sips_payment',
      'sips_passphrase' => 'test',
    ]);

    $this->paymentMethod = $this->createEntity('commerce_payment_method', [
      'uid' => $this->loggedInUser->id(),
      'type' => 'sips',
      'payment_gateway' => 'sips_payment',
    ]);

  }

}
