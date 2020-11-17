<?php
/**
 * @file
 * Provides webhook endpoint for GoCardless.
 */
require_once 'CRM/Core/Page.php';

class CRM_GoCardless_Page_Webhook extends CRM_Core_Page {

  /**
   * Main entry point for legacy webhooks.
   */
  public function run() {
    // As we don't know the payment processor, we pass in NULL.
    CRM_Core_Payment_GoCardlessIPN::run(NULL);
    CRM_Utils_System::civiExit();
  }

}
