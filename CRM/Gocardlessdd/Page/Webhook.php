<?php
/**
 * @file
 * Provides webhook endpoint for GoCardless.
 */

require_once 'CRM/Core/Page.php';

class CRM_Gocardlessdd_Page_Webhook extends CRM_Core_Page {

  public static $implemented_webhooks = [
    'payments' => ['confirmed', 'failed'],
    'mandate' => ['expired', 'disabled'],
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

    if (!$this->checkWebhookSource($headers, $raw_payload)) {
      // Invalid webhook call.
      header("HTTP/1.1 498 Invalid Token");
      CRM_Utils_System::civiExit();
    }

    // Process the events
    header("HTTP/1.1 200 OK");
    CRM_Utils_System::civiExit();

    //CRM_Core_Error::Fatal("ERROR: Stripe triggered a Webhook on an invoice not found in civicrm_contribution_recur: " . $stripe_event_data);
    parent::run();
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
    // Index by event id is safe because it's unique, and it makes testing easier :-)
    $this->events = [];
    foreach ($data->events as $event) {
      if (isset(CRM_Gocardlessdd_Page_Webhook::$implemented_webhooks[$event->resource_type])
        && in_array($event->action, CRM_Gocardlessdd_Page_Webhook::$implemented_webhooks[$event->resource_type])) {
        $this->events[$event->id] = $event;
      }
    }
  }
}
