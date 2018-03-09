<?php

/**
 * @file
 * Payment Processor for GoCardless.
 */


/**
 *
 */
class CRM_Core_Payment_GoCardless extends CRM_Core_Payment {

  /** @var bool TRUE if test mode.  */
  protected $test_mode;

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   * @param $paymentProcessor
   */
  function __construct($mode, &$paymentProcessor) {
    $this->test_mode = ($mode == 'test');
    $this->_paymentProcessor = $paymentProcessor;
    // ? $this->_processorName    = ts('GoCardless Processor');
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * artfulrobot: I'm not clear how this is used. It's called when saving a
   * PaymentProcessor from the UI but its output is never shown to the user,
   * so presumably it's used elsewhere. YES: it's used when you visit the
   * Contributions Tab of a contact, for example.
   *
   * @return string the error message if any
   */
  public function checkConfig() {

    if (empty($this->_paymentProcessor['user_name'])) {
      $errors []= ts("Missing " . $this->_paymentProcessor['api.payment_processor_type.getsingle']['user_name_label']);
    }
    if (empty($this->_paymentProcessor['url_api'])) {
      $errors []= ts("Missing URL for API. This sould probably be "
        . $this->_paymentProcessor['api.payment_processor_type.getsingle']['url_api_default']
        . " (for live payments), or "
        . $this->_paymentProcessor['api.payment_processor_type.getsingle']['url_api_test_default']
        . " (for test/sandbox)");
    }

    if ( !empty( $errors ) ) {
      $errors = "<ul><li>" . implode( '</li><li>', $errors ) . "</li></ul>";
      CRM_Core_Session::setStatus($errors, 'Error', 'error');
      return $errors;
    }

    /* This isn't appropriate as this is called in various places, not just on saving the payment processor config.

    $webhook_url = CRM_Utils_System::url('civicrm/gocardless/webhook', $query=NULL, $absolute=TRUE, $fragment=NULL,  $htmlize=TRUE, $frontend=TRUE);
    CRM_Core_Session::setStatus("Ensure your webhooks are set up at GoCardless. URL is <a href='$webhook_url' >$webhook_url</a>"
      , 'Set up your webhook');
    */
  }

  /**
   * Build the user-facing form.
   *
   * This is minimal because most data is taken in a Go Cardless redirect flow.
   *
   * Nb. Other direct debit schemes's pricing is based upon the number of
   * collections but GC's is just based on transactions. While it may still be
   * nice to offer a collection day choice, this is not implemented here yet.
   */
  public function buildForm(&$form) {
    //$form->add('select', 'preferred_collection_day', ts('Preferred Collection Day'), $collectionDaysArray, FALSE);
  }
  /** The only implementation is sending people off-site using doTransferCheckout.
   */
  public function doDirectPayment(&$params) {
    CRM_Core_Error::fatal(ts('This function is not implemented'));
  }

  /**
   * Sends user off to Gocardless.
   *
   * Note: the guts of this function are in doTransferCheckoutWorker() so that
   * can be tested without issuing a redirect.
   *
   * This is called by civicrm_api3_contribution_transact calling doPayment on the payment processor.
   */
  public function doTransferCheckout( &$params, $component ) {
    $url = $this->doTransferCheckoutWorker($params, $component);
    CRM_Utils_System::redirect($url);
  }
  /**
   * Processes the contribution page submission for doTransferCheckout.
   *
   * @param array &$params keys:
   * - qfKey
   * - contactID
   * - description
   * - contributionID
   * - entryURL
   * - contributionRecurID (optional)
   *
   * @return string URL to redirec to.
   */
  public function doTransferCheckoutWorker( &$params, $component ) {
    // Where should the user come back on our site after completing the GoCardless offsite process?
    $url = CRM_Utils_System::url(
      ($component == 'event') ? 'civicrm/event/register' : 'civicrm/contribute/transact',
      "_qf_ThankYou_display=1&qfKey={$params['qfKey']}"."&cid={$params['contactID']}",
      true, null, false );

    try {
      // Get a GoCardless redirect flow URL.
      $redirect_flow = CRM_GoCardlessUtils::getRedirectFlow([
        "test_mode"            => (bool) $this->_paymentProcessor['is_test'],
        "session_token"        => $params['qfKey'],
        "success_redirect_url" => $url,
        "description"          => isset($params['description']) ? $params['description'] : NULL,
      ]);

      // Store some details on the session that we'll need when the user returns from GoCardless.
      // Key these by the redirect flow id just in case the user simultaneously
      // does two things at once in two tabs (??)
      $sesh = CRM_Core_Session::singleton();
      $sesh_store = $sesh->get('redirect_flows', 'GoCardless');
      $sesh_store = $sesh_store ? $sesh_store : [];
      $sesh_store[$redirect_flow->id] = [
        'test_mode'            => (bool) $this->_paymentProcessor['is_test'],
        'session_token'        => $params['qfKey'],
        'payment_processor_id' => $this->_paymentProcessor['id'],
        "description"          => $params['description'],
      ];
      foreach (['contributionID', 'contributionRecurID', 'contactID', 'membershipID'] as $_) {
        if (!empty($params[$_])) {
          $sesh_store[$redirect_flow->id][$_] = $params[$_];
        }
      }
      $sesh->set('redirect_flows', $sesh_store, 'GoCardless');

      // Redirect user.
      return $redirect_flow->redirect_url;
    }
    catch (\Exception $e) {
      CRM_Core_Session::setStatus('Sorry, there was an error contacting the payment processor GoCardless.', ts("Error"), "error");
      CRM_Core_Error::debug_log_message('CRM_Core_Payment_GoCardless::doTransferCheckoutWorker exception: ' . $e->getMessage() . "\n\n" . $e->getTraceAsString(), FALSE, 'GoCardless', PEAR_LOG_ERR);
      return $params['entryURL'];
    }
  }

}
