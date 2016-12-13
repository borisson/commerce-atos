<?php

namespace Drupal\Tests\commerce_atos\Functional;

use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_price\Price;
use Drupal\commerce_store\StoreCreationTrait;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;
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
  protected function getAdministratorPermissions() {
    return array_merge([
      'administer commerce_payment_gateway',
      'administer commerce_payment',
    ], parent::getAdministratorPermissions());
  }


}
