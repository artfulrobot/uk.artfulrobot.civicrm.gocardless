<?php
/**
 * @file
 * This is a script EXAMPLE provided for **developers** wanting to re-run webhooks.
 *
 * Get the data for headers and body from the GoCardless website UI.
 *
 * Run this using:
 *
 *     cv scr run-webhook.php
 *
 */

if (php_sapi_name() !== 'cli') {
  exit;
}

// Copy and paste these strings from your logs.
$headers = '{"Webhook-Signature":"xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"}';
$body = '{"events":[{"id":"EV0028Dxxxxxxx","created_at":"2019-01-11T11:05:13.962Z","resource_type":"payments","action":"confirmed","links":{"payment":"PM000DX4xxxxxx"},"details":{"origin":"gocardless","cause":"payment_confirmed","description":"Enough time has passed since the payment was submitted for the banks to return an error, so this payment is now confirmed."},"metadata":{}}]}';
$headers = json_decode($headers, TRUE);

civicrm_initialize();

$webhook = new CRM_GoCardless_Page_Webhook();
$webhook->parseWebhookRequest($headers, $body);
$webhook->processWebhookEvents();
CRM_Utils_System::civiExit();


