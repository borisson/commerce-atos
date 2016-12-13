<?php

namespace Drupal\commerce_atos\Plugin\Commerce\CheckoutPane;

use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneBase;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutPane\CheckoutPaneInterface;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Calculator;
use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Url;
use Drupal\commerce_atos\Plugin\Commerce\PaymentGateway\SIPSPaymentGateway;
use Sips\Passphrase;
use Sips\PaymentRequest;
use Sips\ShaComposer\AllParametersShaComposer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * SIPS Payment checkout pane.
 *
 * @CommerceCheckoutPane(
 *   id = "sips_payment_offsite",
 *   label = @Translation("SIPS Payment offsite"),
 *   default_step = "order_information",
 *   wrapper_element = "fieldset",
 * )
 */
class SIPSPaymentOffsite extends CheckoutPaneBase implements CheckoutPaneInterface, ContainerFactoryPluginInterface {

  /**
   * Creates a payment entity for this order.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface
   *   A payment for this order.
   */
  protected function createPayment() {
    /* @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = $this->order->payment_gateway->entity;
    $payment_method = $this->order->payment_method->entity;

    $now = REQUEST_TIME;
    $mode = SIPSPaymentGateway::MODES[$payment_gateway->getPlugin()->configuration['mode']];

    // Create a payment entity which will be initially in "pending mode":
    // "pending mode": means that the payment was still not finished in SIPS,
    // so the user is in the platform filling in the credit card or he/she had
    // problems to pay that time (timeout/closed the browser/etc).
    $payment = Payment::create([
      'order_id' => $this->order->id(),
      'payment_method' => $payment_method->id(),
      'payment_gateway' => $payment_gateway->id(),
      // Give it a status: pending/failed/done.
      'state' => 'new',
      'remote_state' => 'pending',
      // Give it a max_timestamp of 24 hours.
      'authorization_expires' => $now + (24 * 60 * 60),
      // Give it a transaction reference.
      'remote_id' => $this->order->id() . $now,
      'test' => $mode != PaymentRequest::PRODUCTION,
      'amount' => $this->order->getTotalPrice(),
    ]);

    $payment->save();

    return $payment;
  }

  /**
   * Creates a request to SIPS to initiate the user redirection.
   *
   * The PaymentRequest is prepared with the information from the
   * SIPSPaymentGateway and also with the order. Once the request is sent, SIPS
   * will return an HTML with a message and an automatic redirection to SIPS
   * platform.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment we should create a redirect for.
   *
   * @throws \Drupal\Core\Form\EnforcedResponseException
   */
  protected function redirectToSips(PaymentInterface $payment) {

    /* @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = $this->order->payment_gateway->entity;
    $payment_method = $this->order->payment_method->entity;

    $config = $payment_gateway->getPlugin()->getConfiguration();

    $passphrase = new Passphrase($config['sips_passphrase']);
    $shaComposer = new AllParametersShaComposer($passphrase);

    $paymentRequest = new PaymentRequest($shaComposer);

    $sips_url = SIPSPaymentGateway::MODES[$config['mode']];

    $paymentRequest->setSipsUri($sips_url);

    $paymentRequest->setMerchantId($config['sips_merchant_id']);
    $paymentRequest->setKeyVersion($config['sips_key_version']);

    $url = Url::fromRoute('commerce_atos.handle_response',
      [
        'commerce_order' => $this->order->id(),
        'commerce_payment' => $payment->id(),
      ], ['absolute' => TRUE])->toString();

    $paymentRequest->setNormalReturnUrl($url);

    $paymentRequest->setTransactionReference($payment->getRemoteId());

    // Set an amount in cents.
    $paymentRequest->setAmount(intval(Calculator::multiply($this->order->getTotalPrice()
      ->getNumber(), '100', 0)));

    $paymentRequest->setCurrency($this->order->getTotalPrice()
      ->getCurrencyCode());

    $language_code = $this->order->language()->getId();

    // If the order language is not in one of the SIPS allowed languages, it
    // will fall back to english.
    if (!in_array($language_code, $paymentRequest->allowedlanguages)) {
      $language_code = 'en';
    }

    $paymentRequest->setLanguage($language_code);
    $paymentRequest->setPaymentBrand($payment_method->get('sips_payment_option')->value);

    try {
      $paymentRequest->validate();

    }
    catch (\Exception $e) {
      \Drupal::logger('commerce_atos')
        ->warning('Payment request did not validate. Reason: ' . $e->getMessage());
      $response = new LocalRedirectResponse(Url::fromRoute('commerce_cart.page')
        ->toString(TRUE)
        ->getGeneratedUrl());
      $response->send();
      return;
    }

    /* @var \GuzzleHttp\Client $client */
    $client = \Drupal::httpClient();
    $options['form_params'] = [
      'Data' => $paymentRequest->toParameterString(),
      'InterfaceVersion' => $config['sips_interface_version'],
      'Seal' => $paymentRequest->getShaSign(),
    ];

    $payment_method->set('sips_seal', $paymentRequest->getShaSign());
    $payment_method->save();

    $response = $client->request('POST', $paymentRequest->getSipsUri(), $options);

    // We should emulate a drupal_goto(), which typically it can be done
    // returning a Response when building a form. But this form is built with
    // Panes, and panes are currently not able to return a Response object.
    // Therefore Panes cannot do drupal_goto() without throwing this Exception.
    // @todo Exceptions should not be used for code flow control. However, the
    //   Form API does not integrate with the HTTP Kernel based architecture of
    //   Drupal 8. In order to resolve this issue properly it is necessary to
    //   completely separate form submission from rendering.
    //   @see https://www.drupal.org/node/2367555
    throw new EnforcedResponseException(new Response($response->getBody()));
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaneForm(array $pane_form, FormStateInterface $form_state, array &$complete_form) {

    $payment = $this->createPayment();
    $response = $this->redirectToSips($payment);

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, CheckoutFlowInterface $checkout_flow = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $checkout_flow
    );
  }

}
