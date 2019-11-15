<?php
/**
 * @file
 * Provides webhook endpoint for GoCardless.
 *
 * @todo this should probably be implemented as an IPN and the code moved into
 * CRM_Core_Payment_GoCardless::handlePaymentNotification() However, I was not
 * sure about how CiviCRM processes those as it seems to rely on $_REQUEST
 * which does strange things with JSON input.
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
  /** @var CRM_Core_Payment_GoCardless payment processor loaded from CiviCRM API paymentProcessor entity */
  protected $payment_processor;
  /** Timestamp for logs. */
  public $now;

  public function run() {

    // We need to check the input against the test and live payment processors.
    $raw_payload = file_get_contents('php://input');
    if (!function_exists('getallheaders')) {
      // https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/23
      // Some server configs do not provide getallheaders().
      // We only care about the Webhook-Signature header so try to extract that from $_SERVER.
      $headers = [];
      if (isset($_SERVER['HTTP_WEBHOOK_SIGNATURE'])) {
        $headers['Webhook-Signature'] = $_SERVER['HTTP_WEBHOOK_SIGNATURE'];
      }
    }
    else {
      $headers = getallheaders();
    }

    // debugging:
    $this->now = date('Y-m-d:H:i:s');
    CRM_Core_Error::debug_log_message("Webhook $this->now body:\n".  $raw_payload, FALSE, 'GoCardless', PEAR_LOG_INFO);
    CRM_Core_Error::debug_log_message("Webhook $this->now headers:\n". json_encode($headers), FALSE, 'GoCardless', PEAR_LOG_INFO);

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
        CRM_Core_Error::debug_log_message("Webhook $this->now Ignored webhook event. Reason: " . $e->getMessage(), FALSE, 'GoCardless', PEAR_LOG_NOTICE);
        if ($throw) {
          throw $e;
        }
      }
      catch (Exception $e) {
        CRM_Core_Error::debug_log_message("Webhook $this->now Failed event. Reason: " . $e->getMessage(), FALSE, 'GoCardless', PEAR_LOG_ERR);
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

    // Loop through all GoCardless Payment Processors until we find one for which the signature is valid.
    $candidates = civicrm_api3('PaymentProcessor', 'get', ['payment_processor_type_id' => "GoCardless", 'is_active' => 1]);
    $valid = FALSE;
    foreach ($candidates['values'] as $payment_processor_id => $pp) {
      $token = isset($pp['signature']) ? $pp['signature']  : '';
      $calculated_signature = hash_hmac("sha256", $raw_payload, $token);
      if ($token && $provided_signature === $calculated_signature) {
        $valid = TRUE;
        $this->test_mode = !empty($pp['is_test']);
        $this->payment_processor = Civi\Payment\System::singleton()->getByProcessor($pp);
        break;
      }
    }
    if (!$valid) {
      throw new InvalidArgumentException("Invalid signature in request. (Or payment processor is not active)");
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
    $payment = $this->getAndCheckGoCardlessPayment($event, ['confirmed', 'paid_out']);
    $recur = $this->getContributionRecurFromSubscriptionId($payment->links->subscription);

    // Ensure that the recurring contribution record is set to In Progress.
    // ...but only if it's currently Pending or Overdue.
    // ...AND check that it has not been cancelled.
    $contrib_recur_statuses = CRM_Contribute_BAO_ContributionRecur::buildOptions('contribution_status_id', 'validate');
    if (in_array($contrib_recur_statuses[$recur['contribution_status_id']], ['Pending', 'Overdue'])) {
      // It would be Overdue if the last payment failed.
      //
      // It would be Pending if this is the first payment on a new mandate -
      // and it was set up before v1.8 of this was in use. We now set the
      // recurring record to In Progress as soon as the mandate and subscription
      // are setup, so we should not encounter too many Pending contribution
      // recur records any longer.
      //
      // In these situations, having just received a successful payment
      // (subject to any "late failures" yet to occur) then the recur record
      // should be set to "In Progress".
      //
      // However we don't do this for other statuses (namely Cancelled, or
      // Completed or Failed). A payment may come in on a Cancelled mandate, if your
      // timing is unluckly, it does not mean the mandate is In Progress.
      // See https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/54
      //
      civicrm_api3('ContributionRecur', 'create', [
        'id'                     => $recur['id'],
        'contribution_status_id' => 'In Progress',
        'failure_count'          => 0, // Reset the failure count.
      ]);
    }

    // There's all sorts of other fields on recur - do we need to set them?
    // e.g. date of next expected payment, date of last update etc. @todo

    // Prepare to update the contribution records.
    $contribution = [
      'total_amount'           => number_format($payment->amount / 100, 2, '.', ''),
      'receive_date'           => $payment->charge_date,
      'trxn_date'              => $payment->charge_date,
      'trxn_id'                => $payment->id,
      'contribution_recur_id'  => $recur['id'],
      'financial_type_id'      => $recur['financial_type_id'],
      'contact_id'             => $recur['contact_id'],
      'is_test'                => $this->test_mode ? 1 : 0,
      'is_email_receipt'       => 0, // Do not send email receipts. This might annoy some people. Be nice if it was a setting.
    ];
    // Note: the param called 'trxn_date' which is used for membership date
    // calculations. If it's not given, today's date gets used.

    // From CiviCRM 5.19.1 (and possibly earlier) we need to specify the
    // membership_id on the contribution, otherwise the membership does not get
    // updated. This may/may not be related to the work on implementing Order API etc.
    $memberships = civicrm_api3('Membership', 'get', [
      'contribution_recur_id' => $recur['id'],
      'sequential' => 1,
    ]);
    if ($memberships['count'] == 1) {
      $contribution['membership_id'] = $memberships['values'][0]['id'];
    }

    $pending_contribution_id = $this->getPendingContributionId($recur);
    if ($pending_contribution_id) {
      // There's an existing pending contribution.
      $contribution['id'] = $pending_contribution_id;

      // Update the contribution.
      $result = civicrm_api3('Contribution', 'create', $contribution);

      // Now call completetransaction. Note that the only data this updates in
      // the contribution record is trxn_id and fee_amount (which we don't
      // supply).
      $result = civicrm_api3('Contribution', 'completetransaction', $contribution);
      // We're done here.
      return;
    }

    // There is no pending contribution, find the original one.
    $contribution['original_contribution_id'] = $this->getOriginalContributionId($recur);
    $contribution['contribution_status_id'] = 'Completed';

    // Create a copy record of the original contribution and send out email receipt
    $result = civicrm_api3('Contribution', 'repeattransaction', $contribution);
  }

  /**
   * Process webhook for 'payments' resource type, action 'failed'.
   */
  public function doPaymentsFailed($event) {
    $payment = $this->getAndCheckGoCardlessPayment($event, ['failed']);
    $recur = $this->getContributionRecurFromSubscriptionId($payment->links->subscription);

    // Ensure that the recurring contribution record is set to Overdue
    // Nb. others (Stripe) set this to Failed.
    civicrm_api3('ContributionRecur', 'create', [
      'id'                     => $recur['id'],
      'failure_count'          => ($recur['failure_count'] ?? 0) + 1,
      'contribution_status_id' => 'Overdue',
    ]);

    // Prepare to update the contribution records.
    $contribution = [
      'total_amount'           => number_format($payment->amount / 100, 2, '.', ''),
      'receive_date'           => $payment->charge_date,
      'trxn_id'                => $payment->id,
      'contribution_status_id' => 'Failed',
      'contribution_recur_id'  => $recur['id'],
      'financial_type_id'      => $recur['financial_type_id'],
      'contact_id'             => $recur['contact_id'],
      'is_test'                => $this->test_mode ? 1 : 0,
    ];

    $pending_contribution_id = $this->getPendingContributionId($recur);
    if ($pending_contribution_id) {
      // There's an existing pending contribution.
      $contribution['id'] = $pending_contribution_id;
    }
    else {
      // There is no pending contribution, but perhaps this is a late failure?
      // Can we find a contribution with the same trxn_id?
      $result = civicrm_api3('Contribution', 'get', [
        'sequential'            => 1,
        'trxn_id'               => $payment->id,
        'contribution_recur_id' => $recur['id'],
        'is_test'               => $this->test_mode ? 1 : 0,
      ]);
      if ($result['count'] >0) {
        // Yes, there is one! We'll update it to refunded.
        $contribution['id'] = $result['values'][0]['id'];

        // CiviCRM will not let us change from Completed to Failed.
        // So we have to use Refunded.
        $contribution['contribution_status_id'] = 'Refunded';

        // Include Late Failure in the notes.
        $note = ($result['values'][0]['note'] ?? '');
        if ($note) {
          $note .= "\n";
        }
        $contribution['note'] = $note . "Late Failure";
      }
      else {
        // There is no pending contribution, nor one that relates to this GC payment.
        // So we'll be creating a new Contribution.
        // For this we need to note the original_contribution_id, where possible.
        $contribution['original_contribution_id'] = $this->getOriginalContributionId($recur);
      }
    }

    // Make the changes.
    civicrm_api3('Contribution', 'create', $contribution);
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
   * @param array $expected_status array of acceptable stati
   * @return NULL|\GoCardless\Resources\Payment
   */
  public function getAndCheckGoCardlessPayment($event, $expected_status) {
    $gc_api = $this->payment_processor->getGoCardlessApi();
    // According to GoCardless we need to check that the status of the object
    // has not changed since the webhook was fired, so we re-load it and test.
    $payment = $gc_api->payments()->get($event->links->payment);
    if (!in_array($payment->status, $expected_status)) {
      // Payment status is no longer as expected, ignore this webhook.
      throw new CRM_GoCardless_WebhookEventIgnoredException("Webhook out of date, expected status "
        . implode("' or '", $expected_status)
        . ", got '{$payment->status}'");
    }

    // We expect a subscription link, but not all payments have this.
    if (empty($payment->links->subscription)) {
      // This payment is not part of a subscription. Assume it's not of interest to us.
      throw new CRM_GoCardless_WebhookEventIgnoredException("Ignored payment that does not belong to a subscription.");
    }

    return $payment;
  }
  /**
   * Looks up the ContributionRecur record for the given GC subscription Id.
   *
   * @throws CRM_GoCardless_WebhookEventIgnoredException
   * @param string $subscription_id
   * @return array
   */
  public function getContributionRecurFromSubscriptionId($subscription_id) {
    if (!$subscription_id) {
      throw new CRM_GoCardless_WebhookEventIgnoredException("No subscription_id data");
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
   * See if we have a pending contribution for the given contribution_record record.
   *
   * @param array $recur (only the 'id' key is used)
   * @return null|int Either the contribution id of the pending contribution, or NULL
   */
  public function getPendingContributionId($recur) {
    // See if there's a Pending contribution we can update. xxx ??? No contribs at all?
    $incomplete_contribs = civicrm_api3('Contribution', 'get',[
      'sequential' => 1,
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => "Pending",
      'is_test' => $this->test_mode ? 1 : 0,
    ]);
    if ($incomplete_contribs['count'] > 0) {
      // Found one (possibly more than one, edge case - ignore and take first).
      return $incomplete_contribs['values'][0]['id'];
    }
  }
  /**
   * Get the first payment for this recurring contribution.
   *
   * @param array $recur (only the 'id' key is used)
   * @return null|int Either the contribution id of the original contribution, or NULL
   */
  public function getOriginalContributionId($recur) {
    // See if there's a Completed contribution we can update. xxx ??? No contribs at all?
    $contribs = civicrm_api3('Contribution', 'get',[
      'sequential'             => 1,
      'contribution_recur_id'  => $recur['id'],
      'contribution_status_id' => "Completed",
      'is_test'                => $this->test_mode ? 1 : 0,
      'options'                => ['sort' => 'receive_date', 'limit' => 1],
    ]);
    if ($contribs['count'] > 0) {
      // Found one (possibly more than one, edge case - ignore and take first).
      return $contribs['values'][0]['id'];
    }
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
    $gc_api = $this->payment_processor->getGoCardlessApi();
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
    // There should only be one, but just in case...
    while ($pending_contribution_id = $this->getPendingContributionId($recur)) {
        civicrm_api3('Contribution', 'create', [
          'id' => $pending_contribution_id,
          'contribution_status_id' => "Cancelled",
        ]);
    }
  }
  /**
   * This is only to help mock things for tests. Do not use!
   *
   * @param CRM_Core_Payment_GoCardless $pp
   */
  public function setPaymentProcessor($pp) {
    $this->payment_processor = $pp;
  }
}
