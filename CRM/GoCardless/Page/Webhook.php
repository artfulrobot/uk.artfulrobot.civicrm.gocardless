<?php
/**
 * @file
 * Provides webhook endpoint for GoCardless.
 */

require_once 'CRM/Core/Page.php';

class CRM_GoCardless_Page_Webhook extends CRM_Core_Page {

  /** @var array names (string) to values (int) */
  public $contribution_status_map;
  public static $implemented_webhooks = [
    'payments' => ['confirmed', 'failed'],
    'subscriptions'  => ['cancelled', 'finished'],
  ];
  /** @var bool */
  protected $test_mode;

  /** @var array of webhook events that we can process */
  public $events;
  /** @var array payment processor loaded from CiviCRM API paymentProcessor entity */
  protected $payment_processor;
  public function run() {

    // We need to check the input against the test and live payment processors.
    $raw_payload = file_get_contents('php://input');
    // debugging:
    file_put_contents('/tmp/gcwebhook-' . date('Y-m-d-His'), $raw_payload);

    $headers = getallheaders();

    try {
      $this->parseWebhookRequest($headers, $raw_payload);
    }
    catch (InvalidArgumentException $e) {
      // Invalid webhook call.
      header("HTTP/1.1 498 Invalid Token");
      CRM_Utils_System::civiExit();
    }

    // Process the events
    header("HTTP/1.1 204 OK");
    $this->processWebhookEvents();
    CRM_Utils_System::civiExit();
  }

  /**
   * Loop the events and process them.
   *
   * @param bool $throw whether to silently log exceptions or chuck them up for
   * someone else to notice. Useful for phpunit tests.
   */
  public function processWebhookEvents($throw=FALSE) {
    foreach ($this->events as $event) {
      try {
        $method = 'do' . ucfirst($event->resource_type) . ucfirst($event->action);
        $this->$method($event);
      }
      catch (CRM_GoCardless_WebhookEventIgnoredException $e) {
        CRM_Core_Error::debug_log_message("Ignored webhook event. Reason: " . $e->getMessage(), FALSE, 'GoCardless', PEAR_LOG_NOTICE);
        if ($throw) {
          throw $e;
        }
      }
      catch (Exception $e) {
        CRM_Core_Error::debug_log_message("Failed event. Reason: " . $e->getMessage(), FALSE, 'GoCardless', PEAR_LOG_ERR);
        if ($throw) {
          throw $e;
        }
      }
    }
  }

  /**
   * Check incomming input for validity and extract the data into properties.
   *
   * Alters $this->test_mode, $this->events.
   *
   * @throws InvalidArgumentException if signature does not match.
   * @return void
   */
  public function parseWebhookRequest($headers, $raw_payload) {

    // Check signature and find appropriate Payment Processor.
    if (empty($headers["Webhook-Signature"])) {
      throw new InvalidArgumentException("Unsigned API request.");
    }
    $provided_signature = $headers["Webhook-Signature"];
    $valid = FALSE;
    foreach([FALSE, TRUE] as $test) {
      $pp = CRM_GoCardlessUtils::getPaymentProcessor($test);
      $token = isset($pp['signature']) ? $pp['signature']  : '';
      $calculated_signature = hash_hmac("sha256", $raw_payload, $token);
      if ($token && $provided_signature == $calculated_signature) {
        $valid = TRUE;
        $this->test_mode = $test;
        $this->payment_processor = $pp;
        break;
      }
    }
    if (!$valid) {
      throw new InvalidArgumentException("Invalid signature in request.");
    }
    $data = json_decode($raw_payload);
    if (!$data || empty($data->events)) {
      throw new InvalidArgumentException("Invalid or missing data in request.");
    }

    // Filter for events that we can handle.
    //
    // Index by event id is safe because it's unique, and it makes testing easier :-)
    $this->events = [];
    $ignored = [];
    foreach ($data->events as $event) {
      if (isset(CRM_GoCardless_Page_Webhook::$implemented_webhooks[$event->resource_type])
        && in_array($event->action, CRM_GoCardless_Page_Webhook::$implemented_webhooks[$event->resource_type])) {
        $this->events[$event->id] = $event;
      }
      else {
        CRM_Core_Error::debug_log_message(
          "Ignored unimplemented webhook event id: '$event->id' resource: '$event->resource_type' action: '$event->action' ",
          FALSE, 'GoCardless', PEAR_LOG_INFO);
      }
    }
  }
  /**
   * Process webhook for 'payments' resource type, action 'confirmed'.
   *
   * A payment has been confirmed as successful.
   * We can look up the contribution recur record from the subscription id and
   * from then we can add a contribution.
   *
   * When the direct debit is first set up, e.g. by a Contribution page, the
   * first payment is already created with status incomplete. So for this
   * reason we look for a contribution like this and update that if we find one
   * instead of adding another.
   */
  public function doPaymentsConfirmed($event) {
    $payment = $this->getAndCheckPayment($event, 'confirmed');
    $recur = $this->getContributionRecurFromSubscriptionId($payment->links->subscription);
    $contribution = $this->updateContributionRecord('Completed', $payment, $recur);

    // Ensure that the recurring contribution record is set to In Progress.
    civicrm_api3('ContributionRecur', 'create', [
      'id' => $recur['id'],
      'contribution_status_id' => 'In Progress',
    ]);
    // There's all sorts of other fields on recur - do we need to set them?
    // e.g. date of next expected payment, date of last update etc. @todo
    // check.
  }

  /**
   * Process webhook for 'payments' resource type, action 'failed'.
   */
  public function doPaymentsFailed($event) {
    $payment = $this->getAndCheckPayment($event, 'confirmed');
    $recur = $this->getContributionRecurFromSubscriptionId($payment->links->subscription);
    $contribution = $this->updateContributionRecord('Failed', $payment, $recur);

    // Ensure that the recurring contribution record is set to In Progress.
    civicrm_api3('ContributionRecur', 'create', [
      'id' => $recur['id'],
      'contribution_status_id' => 'Overdue', // is this appropriate? @todo
    ]);
    // There's all sorts of other fields on recur - do we need to set them?
    // e.g. date of next expected payment, date of last update etc. @todo
    // check.

  }
  /**
   * Process webhook for 'mandate' resource type, action 'finished'.
   *
   * In this case the subscription has come to its natural end.
   */
  public function doSubscriptionsFinished($event) {
    $subscription = $this->getAndCheckSubscription($event, 'finished');
    $recur = $this->getContributionRecurFromSubscriptionId($subscription->id);

    $update = [
      'id' => $recur['id'],
      'contribution_status_id' => 'Completed',
      'end_date' => !empty($subscription->end_date) ? $subscription->end_date :  date('Y-m-d'),
    ];
    civicrm_api3('ContributionRecur', 'create', $update);
    $this->cancelPendingContributions($recur);
  }
  /**
   * Process webhook for 'mandate' resource type, action 'cancelled'.
   *
   * This covers a number of reasons. Typically, the supporter cancelled.
   */
  public function doSubscriptionsCancelled($event) {
    $subscription = $this->getAndCheckSubscription($event, 'cancelled');
    $recur = $this->getContributionRecurFromSubscriptionId($subscription->id);
    $update = [
      'id' => $recur['id'],
      'contribution_status_id' => 'Cancelled',
      'end_date' => !empty($subscription->end_date) ? $subscription->end_date :  date('Y-m-d'),
    ];
    civicrm_api3('ContributionRecur', 'create', $update);
    $this->cancelPendingContributions($recur);
  }

  /**
   * Helper to load and return GC payment object.
   *
   * We check that the status is expected and that the payment belongs to
   * subscription.
   *
   * @throws CRM_GoCardless_WebhookEventIgnoredException
   * @param array $event
   * @param string $expected_status
   * @return NULL|\GoCardless\Resources\Payment
   */
  public function getAndCheckPayment($event, $expected_status) {
    $gc_api = CRM_GoCardlessUtils::getApi($this->test_mode);
    // According to GoCardless we need to check that the status of the object
    // has not changed since the webhook was fired, so we re-load it and test.
    $payment = $gc_api->payments()->get($event->links->payment);
    if ($payment->status != $expected_status) {
      // Payment status is no longer confirmed, ignore this webhook.
      throw new CRM_GoCardless_WebhookEventIgnoredException("Webhook out of date, expected status '$expected_status', got '{$payment->status}'");
    }

    // We expect a subscription link, but not payments have this.
    if (empty($payment->links->subscription)) {
      // This payment is not part of a subscription. Assume it's not of interest to us.
      throw new CRM_GoCardless_WebhookEventIgnoredException("Ignored payment that does not belong to a subscription.");
    }

    return $payment;
  }
  /**
   * Looks up the ContributionRecur record for the given GC subscription Id.
   *
   * @throws GoCardlessWebhookEventIgnored
   * @param string $subscription_id
   * @return array
   */
  public function getContributionRecurFromSubscriptionId($subscription_id) {
    if (!$subscription_id) {
      throw new GoCardlessWebhookEventIgnored("No subscription_id data");
    }

    // Find the recurring payment by the given subscription which will be
    // stored in the trxn_id field.
    try {
      $recur = civicrm_api3('ContributionRecur', 'getsingle', [
        'trxn_id' => $subscription_id,
      ]);
    }
    catch (CiviCRM_API3_Exception $e) {
      throw new CRM_GoCardless_WebhookEventIgnoredException("No matching recurring contribution record for trxn_id {$subscription_id}");
    }
    return $recur;
  }
  /**
   * Update the Contribution record.
   *
   * Code shared by doPaymentsConfirmed and doPaymentsFailed.
   *
   * @todo how does Stripe duplicate contributions? Does some data come from the recur and some from the payment?
   *
   * @param string $status
   * @param \GoCardless\Resources\Payment $payment
   * @param array $recur ContributionRecur
   * @return array
   */
  public function updateContributionRecord($status, $payment, $recur) {
    $update = [
      'total_amount'           => number_format($payment->amount / 100, 2, '.', ''),
      'receive_date'           => $payment->charge_date,
      'trxn_id'                => $payment->id,
      'contribution_status_id' => $status,
      'contribution_recur_id'  => $recur['id'],
      'financial_type_id'      => $recur['financial_type_id'],
      'contact_id'             => $recur['contact_id'],
      'is_test'                => $this->test_mode ? 1 : 0,
    ];

    // See if there's a Pending contribution we can update. xxx ??? No contribs at all?
    $incomplete_contribs = civicrm_api3('Contribution', 'get',[
      'sequential' => 1,
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => "Pending",
      'is_test' => $this->test_mode ? 1 : 0,
    ]);
    if ($incomplete_contribs['count'] > 0) {
      // Found one (possibly more than one, edge case - ignore and take first).
      $update['id'] = $incomplete_contribs['values'][0]['id'];
    }

    civicrm_api3('Contribution', 'create', $update);
  }
  /**
   * Helper to load and return GC subscription object.
   *
   * We check that the status is expected.
   *
   * @param array $event
   * @param string $expected_status
   * @return NULL|\GoCardless\Resources\Subscription
   */
  public function getAndCheckSubscription($event, $expected_status) {
    $gc_api = CRM_GoCardlessUtils::getApi($this->test_mode);
    // According to GoCardless we need to check that the status of the object
    // has not changed since the webhook was fired, so we re-load it and test.
    $subscription = $gc_api->subscriptions()->get($event->links->subscription);
    if ($subscription->status != $expected_status) {
      // Payment status is no longer confirmed, ignore this webhook.
      throw new CRM_GoCardless_WebhookEventIgnoredException("Webhook out of date, expected status '$expected_status', got '{$subscription->status}'");
    }

    return $subscription;
  }
  /**
   * Cancel any Pending Contributions from this recurring contribution.
   *
   * @param array $recur
   */
  public function cancelPendingContributions($recur) {
    $incomplete_contribs = civicrm_api3('Contribution', 'get',[
      'sequential' => 1,
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => "Pending",
      'is_test' => $this->test_mode ? 1 : 0,
    ]);
    if ($incomplete_contribs['count'] > 0) {
      foreach($incomplete_contribs['values'] as $contribution) {
        civicrm_api3('Contribution', 'create', [
          'id' => $contribution['id'],
          'contribution_status_id' => "Cancelled",
        ]);
      }
    }
  }
}
