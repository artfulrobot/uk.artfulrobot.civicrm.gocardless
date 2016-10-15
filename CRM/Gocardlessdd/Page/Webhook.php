<?php
/**
 * @file
 * Provides webhook endpoint for GoCardless.
 */

require_once 'CRM/Core/Page.php';

class CRM_Gocardlessdd_Page_Webhook extends CRM_Core_Page {

  /** @var bool */
  protected $test_mode;

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
   * Check incomming input for validity.
   */
  public function checkWebhookSource($headers, $raw_payload) {

    $provided_signature = isset($headers["Webhook-Signature"]) ? $headers["Webhook-Signature"] : '';
    $valid = FALSE;
    foreach([FALSE, TRUE] as $test) {
      $pp = CRM_GoCardlessUtils::getPaymentProcessor($test);
      $token = $pp['signature'];
      $calculated_signature = hash_hmac("sha256", $raw_payload, $token);
      if ($token && $provided_signature == $calculated_signature) {
        $valid = TRUE;
        $this->test_mode = $test;
        break;
      }
    }

  }
}
