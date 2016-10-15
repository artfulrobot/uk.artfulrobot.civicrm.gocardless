<?php
/**
 * @file
 * Provides webhook endpoint for GoCardless.
 */

require_once 'CRM/Core/Page.php';

class CRM_Gocardlessdd_Page_Webhook extends CRM_Core_Page {
  public function run() {

    $data_raw = file_get_contents("php://input");
    $data = json_decode($data_raw);

    $token = getenv("GC_WEBHOOK_SECRET");

    $raw_payload = file_get_contents('php://input');

    $headers = getallheaders();
    $provided_signature = $headers["Webhook-Signature"];

    $calculated_signature = hash_hmac("sha256", $raw_payload, $token);

    if ($provided_signature == $calculated_signature) {
      // Process the events

      header("HTTP/1.1 200 OK");
    } else {
      header("HTTP/1.1 498 Invalid Token");
    }

        //CRM_Utils_System::civiExit();
          //CRM_Core_Error::Fatal("ERROR: Stripe triggered a Webhook on an invoice not found in civicrm_contribution_recur: " . $stripe_event_data);
    parent::run();
  }
}
