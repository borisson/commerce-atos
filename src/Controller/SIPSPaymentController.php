<?php

namespace Drupal\commerce_worldline\Controller;

use Drupal\commerce_checkout\CheckoutOrderManagerInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Routing\LocalRedirectResponse;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Sips\Passphrase;
use Sips\PaymentResponse;
use Sips\ShaComposer\AllParametersShaComposer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * SIPS response handler.
 */
class SIPSPaymentController extends ControllerBase {

  /**
   * The order we're handling.
   *
   * @var \Drupal\commerce_order\Entity\Order
   */
  protected $order;

  /**
   * The payment we're handling.
   *
   * @var \Drupal\commerce_payment\Entity\Payment
   */
  protected $payment;

  /**
   * The checkout order manager.
   *
   * @var \Drupal\commerce_checkout\CheckoutOrderManagerInterface
   */
  protected $checkoutOrderManager;

  /**
   * The order's checkout flow plugin.
   *
   * @var \Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface
   */
  protected $checkoutFlow;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * Constructs a new CheckoutController object.
   *
   * @param \Drupal\commerce_checkout\CheckoutOrderManagerInterface $checkout_order_manager
   *   The checkout order manager.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   */
  public function __construct(CheckoutOrderManagerInterface $checkout_order_manager, EventDispatcherInterface $event_dispatcher) {
    $this->checkoutOrderManager = $checkout_order_manager;
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('commerce_checkout.checkout_order_manager'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * Receives the route parameters and delegates to response()
   *
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route.
   *
   * @return \Drupal\Core\Routing\LocalRedirectResponse
   *   Redirect to previous/next step or the cart page.
   */
  public function handleResponse(RouteMatchInterface $route_match) {
    $this->order = $route_match->getParameter('commerce_order');
    $this->payment = $route_match->getParameter('commerce_payment');

    // Checks if we have an order.
    if (!$this->order) {
      \Drupal::logger('commerce_atos')
        ->warning('User arrived to commerce_atos.handle_response without an order.');
      // We don't have an order, then we cannot take the user to a better place.
      return new LocalRedirectResponse(Url::fromRoute('commerce_cart.page')
        ->toString(TRUE)
        ->getGeneratedUrl());
    }

    // Checks if we have a valid payment.
    if (!$this->payment || $this->payment->getState()->value != 'new') {
      \Drupal::logger('commerce_atos')
        ->warning('User arrived to commerce_atos.handle_response without a valid payment parameter.');
      // We don't have a valid payment, then we cannot take the user to a better
      // place.
      return new LocalRedirectResponse(Url::fromRoute('commerce_cart.page')
        ->toString(TRUE)
        ->getGeneratedUrl());
    }

    $this->checkoutFlow = $this->checkoutOrderManager->getCheckoutFlow($this->order)
      ->getPlugin();

    return $this->response();
  }

  /**
   * Validates the SIPS response and submits the result of the validation.
   *
   * @return \Drupal\Core\Routing\LocalRedirectResponse
   *   Redirects to the previous or next step.
   */
  public function response() {

    $payment_method = $this->order->payment_method->entity;

    /* @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\PaymentGatewayInterface $payment_gateway */
    $payment_gateway = $this->order->payment_gateway->entity;

    // Prepare the PaymentRequest from the global request data.
    $payment_response = new PaymentResponse($_REQUEST);

    // Make sure the transaction reference matches the payment reference.
    $transaction_reference = $payment_response->getParam('transactionReference');
    $transactionReferenceMatches = $this->payment->getRemoteId() == $transaction_reference;

    // Gets the payment gateway configuration.
    $config = $payment_gateway->getPlugin()->getConfiguration();

    // Prepares the validation.
    $passphrase = new Passphrase($config['sips_passphrase']);
    $shaComposer = new AllParametersShaComposer($passphrase);

    if (!$payment_response->isValid($shaComposer) || !$transactionReferenceMatches || $this->payment->getRemoteState() != 'pending') {
      // This is not a valid response either because:
      // - the passphrase is not matching the request
      // - the transaction reference from the request is not matching the
      //   payment reference
      // - the remote state is not pending
      //
      // We're aborting the request here.
      \Drupal::logger('commerce_atos')
        ->warning('User arrived to commerce_atos.handle_response without valid information: ' .
          '[transaction reference equals: @transactionReference - @transactionReferencePayment]' .
          '[Is remote state pending: @remoteState] [Valid SIPS answer: @valid].', [
            '@transactionReference' => $transaction_reference,
            '@transactionReferencePayment' => $this->payment->getRemoteId(),
            '@remoteState' => $this->payment->getRemoteState(),
            '@valid' => $payment_response->isValid($shaComposer) ? 'Yes' : 'No',
          ]);

      drupal_set_message($this->t('An error occurred while processing your request.'), 'error');

      return new LocalRedirectResponse(Url::fromRoute('commerce_cart.page')
        ->toString(TRUE)
        ->getGeneratedUrl());
    }

    // Update the payment method with the response code.
    $code = $payment_response->getParam('RESPONSECODE');
    $payment_method->set('sips_response_code', $code);
    $payment_method->save();

    // Check if the payment is pending to be processed and.
    if ($this->payment->getRemoteState() == 'pending' && $payment_response->isSuccessful()) {

      // Update the payment information.
      $this->payment->setRemoteState('done');
      $this->payment->set('state', 'capture_completed');
      $this->payment->save();

      // Take the user back to the checkout flow.
      return $this->paymentSuccess();
    }

    // Payment wasn't successful:
    // Update the payment information.
    $this->payment->setRemoteState('failed');
    $this->payment->set('state', 'void');
    $this->payment->save();

    drupal_set_message($this->t('An error occurred in the SIPS platform: [@code] @error',
      [
        '@error' => $this->getResponseCodeDescription($code),
        '@code' => $code,
      ]), 'error');

    // Take the user back to the checkout flow.
    return $this->paymentVoid();
  }

  /**
   * Payment successful.
   *
   * Move the checkout to the next step, and in case it's the complete step
   * then make a workflow transition place.
   *
   * @return \Drupal\Core\Routing\LocalRedirectResponse
   *   A redirect to the next step in the checkout.
   */
  public function paymentSuccess() {
    $url = new Url('commerce_checkout.form', [
      'commerce_order' => $this->order->id(),
      'step' => $this->checkoutFlow->getStepId(),
    ]);

    if ($next_step_id = $this->checkoutFlow->getNextStepId()) {
      $this->order->checkout_step = $next_step_id;

      $url = new Url('commerce_checkout.form', [
        'commerce_order' => $this->order->id(),
        'step' => $next_step_id,
      ]);

      if ($next_step_id == 'complete') {
        // Place the order.
        $transition = $this->order->getState()
          ->getWorkflow()
          ->getTransition('place');
        $this->order->getState()->applyTransition($transition);
      }
    }

    $this->order->save();

    return new LocalRedirectResponse($url->toString(TRUE)->getGeneratedUrl());
  }

  /**
   * Payment unsuccessful.
   *
   * Move the checkout to the previous step, so the user can try again.
   *
   * @return \Drupal\Core\Routing\LocalRedirectResponse
   *   A redirect response back to the previous step.
   */
  public function paymentVoid() {
    $previous_step_id = $this->checkoutFlow->getPreviousStepId();
    $this->order->checkout_step = $previous_step_id;
    $this->order->save();

    $url = new Url('commerce_checkout.form', [
      'commerce_order' => $this->order->id(),
      'step' => $previous_step_id,
    ]);

    return new LocalRedirectResponse($url->toString(TRUE)->getGeneratedUrl());
  }

  /**
   * Get the SIPS response description.
   *
   * @param string $code
   *   Response code.
   *
   * @return string
   *   Description for the response code.
   */
  protected function getResponseCodeDescription($code) {
    $descriptions = [
      '00' => 'Authorisation accepted',
      '02' => 'Authorisation request to be performed via telephone with the issuer, as the card authorisation threshold has been exceeded, if the forcing is authorised for the merchant',
      '03' => 'Invalid distance selling contract',
      '05' => 'Authorisation refused',
      '12' => 'Invalid transaction, verify the parameters transferred in the request.',
      '14' => 'invalid bank details or card security code',
      '17' => 'Buyer cancellation',
      '24' => 'Operation impossible. The operation the merchant wishes to perform is not compatible with the status of the transaction.',
      '25' => 'Transaction not found in the Sips database',
      '30' => 'Format error',
      '34' => 'Suspicion of fraud',
      '40' => 'Function not supported: the operation that the merchant would like to perform is not part of the list of operations for which the merchant is authorised',
      '51' => 'mount too high',
      '54' => 'Card is past expiry date',
      '60' => 'Transaction pending',
      '63' => 'Security rules not observed, transaction stopped',
      '75' => 'Number of attempts at entering the card number exceeded',
      '90' => 'Service temporarily unavailable',
      '94' => 'Duplicated transaction: for a given day, the TransactionReference has already been used',
      '97' => 'Timeframe exceeded, transaction refused',
      '99' => 'Temporary problem at the Sips Office Server level',
    ];

    if (empty($descriptions[$code])) {
      return "Unknown error code - [{$code}]";
    }

    return $descriptions[$code];
  }

}
