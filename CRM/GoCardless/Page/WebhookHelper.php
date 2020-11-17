<?php
use CRM_GoCardless_ExtensionUtil as E;

class CRM_GoCardless_Page_WebhookHelper extends CRM_Core_Page {

  public function run() {

    // Fetch all GoCardless Payment Processors
    $pps = civicrm_api3('PaymentProcessor', 'get', ['payment_processor_type_id' => "GoCardless"])['values'] ?? [];
    foreach ($pps as &$_) {
      // Couldn't figure this out in smarty. Guess I'm not smart.
      $_['webhookUrl'] = CRM_Utils_System::url("civicrm/payment/ipn/$_[id]", NULL, $absolute=TRUE, NULL, $htmlize=TRUE, $frontend=TRUE);
    }
    $this->assign('processors', $pps);

    parent::run();
  }

}
