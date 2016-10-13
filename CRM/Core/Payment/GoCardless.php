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
   * so presumably it's used elsewhere.
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
      return "<ul><li>" . implode( '</li><li>', $errors ) . "</li></ul>";
    }
    else {
      return NULL;
    }
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
   ⬦ $this->_mode = (string [4]) `test`
   ▾ $this->_paymentProcessor = (array [16])
     ⬦ $this->_paymentProcessor['id'] = (string [1]) `7`
     ⬦ $this->_paymentProcessor['domain_id'] = (string [1]) `1`
     ⬦ $this->_paymentProcessor['name'] = (string [10]) `GoCardless`
     ⬦ $this->_paymentProcessor['payment_processor_type_id'] = (string [2]) `22`
     ⬦ $this->_paymentProcessor['is_active'] = (string [1]) `1`
     ⬦ $this->_paymentProcessor['is_default'] = (string [1]) `1`
     ⬦ $this->_paymentProcessor['is_test'] = (string [1]) `1`
     ⬦ $this->_paymentProcessor['user_name'] = (string [40]) `imJx_OwDhVDA29RyLdhW-RJLI4jXFzTCsFAuLK_B`
     ⬦ $this->_paymentProcessor['url_api'] = (string [35]) `https://api-sandbox.gocardless.com/`
     ⬦ $this->_paymentProcessor['class_name'] = (string [18]) `Payment_GoCardless`
     ⬦ $this->_paymentProcessor['billing_mode'] = (string [1]) `4`
     ⬦ $this->_paymentProcessor['is_recur'] = (string [1]) `1`
     ⬦ $this->_paymentProcessor['payment_type'] = (string [1]) `6`
     ⬦ $this->_paymentProcessor['payment_instrument_id'] = (string [1]) `6`
     ▾ $this->_paymentProcessor['api.payment_processor_type.getsingle'] = (array [13])
       ⬦ $this->_paymentProcessor['api.payment_processor_type.getsingle']['id'] = (string [2]) `22`
       ⬦ $this->_paymentProcessor['api.payment_processor_type.getsingle']['name'] = (string [10]) `GoCardless`
       ⬦ $this->_paymentProcessor['api.payment_processor_type.getsingle']['title'] = (string [10]) `GoCardless`
       ⬦ $this->_paymentProcessor['api.payment_processor_type.getsingle']['is_active'] = (string [1]) `1`
       ⬦ $this->_paymentProcessor['api.payment_processor_type.getsingle']['is_default'] = (string [1]) `0`
       ⬦ $this->_paymentProcessor['api.payment_processor_type.getsingle']['user_name_label'] = (string [16]) `API Access Token`
       ⬦ $this->_paymentProcessor['api.payment_processor_type.getsingle']['class_name'] = (string [18]) `Payment_GoCardless`
       ⬦ $this->_paymentProcessor['api.payment_processor_type.getsingle']['url_api_default'] = (string [27]) `https://api.gocardless.com/`
       ⬦ $this->_paymentProcessor['api.payment_processor_type.getsingle']['url_api_test_default'] = (string [35]) `https://api-sandbox.gocardless.com/`
       ⬦ $this->_paymentProcessor['api.payment_processor_type.getsingle']['billing_mode'] = (string [1]) `4`
       ⬦ $this->_paymentProcessor['api.payment_processor_type.getsingle']['is_recur'] = (string [1]) `1`
       ⬦ $this->_paymentProcessor['api.payment_processor_type.getsingle']['payment_type'] = (string [1]) `6`
       ⬦ $this->_paymentProcessor['api.payment_processor_type.getsingle']['payment_instrument_id'] = (string [1]) `1`
     ⬦ $this->_paymentProcessor['payment_processor_type'] = (string [10]) `GoCardless`
   ⬦ $this->baseReturnUrl = (null)
 ▾ $params = (array [34])
   ⬦ $params['qfKey'] = (string [37]) `3da01c04d729872b6807bc64ffbef426_8676`
   ⬦ $params['entryURL'] = (string [152]) `https://sosdev.artfulrobot.uk/wp-admin/admin.php?page=CiviCRM&amp;q=civicrm/contribute/transact&amp;page=CiviCRM&amp;reset=1&amp;action=preview&amp;id=1`
   ⬦ $params['hidden_processor'] = (string [1]) `1`
   ⬦ $params['email-5'] = (string [17]) `rl7@shinyblue.net`
   ⬦ $params['payment_processor_id'] = (string [1]) `7`
   ⬦ $params['priceSetId'] = (string [1]) `3`
   ⬦ $params['price_2'] = (string [1]) `1`
   ⬦ $params['is_recur'] = (string [1]) `1`
   ⬦ $params['frequency_interval'] = (string [1]) `1`
   ⬦ $params['frequency_unit'] = (string [5]) `month`
   ⬦ $params['selectProduct'] = (string [0]) ``
   ⬦ $params['MAX_FILE_SIZE'] = (string [7]) `2097152`
   ⬦ $params['ip_address'] = (string [14]) `81.174.169.217`
   ⬦ $params['amount'] = (string [4]) `1.00`
   ⬦ $params['tax_amount'] = (null)
   ⬦ $params['currencyID'] = (string [3]) `GBP`
   ⬦ $params['is_pay_later'] = (int) 0
   ⬦ $params['invoiceID'] = (string [32]) `cc94b09601be6889c3b975a08a12de74`
   ⬦ $params['is_quick_config'] = (int) 1
   ⬦ $params['description'] = (string [27]) `Online Contribution: Test 1`
   ⬦ $params['accountingCode'] = (null)
   ⬦ $params['address_name-5'] = (string [0]) ``
   ⬦ $params['email'] = (string [17]) `rl7@shinyblue.net`
   ⬦ $params['contributionType_name'] = (string [8]) `Donation`
   ⬦ $params['financialType_name'] = (string [8]) `Donation`
   ⬦ $params['contributionType_accounting_code'] = (string [4]) `4200`
   ⬦ $params['financialType_accounting_code'] = (string [4]) `4200`
   ⬦ $params['contributionPageID'] = (string [1]) `1`
   ⬦ $params['contactID'] = (string [1]) `2`
   ⬦ $params['contributionTypeID'] = (string [1]) `1`
   ⬦ $params['item_name'] = (string [27]) `Online Contribution: Test 1`
   ⬦ $params['contributionID'] = (int) 11
   ⬦ $params['financialTypeID'] = (string [1]) `1`
   ⬦ $params['contributionRecurID'] = (int) 9




   ⬦ $params['email-5'] = (string [17]) `rl7@shinyblue.net`
   ⬦ $params['payment_processor_id'] = (string [1]) `7`
   ⬦ $params['amount'] = (int) 1
   ⬦ $params['selectMembership'] = (string [1]) `1`
   ⬦ $params['is_recur'] = (int) 1
   ⬦ $params['frequency_interval'] = (string [1]) `1`
   ⬦ $params['frequency_unit'] = (string [5]) `month`
   ⬦ $params['invoiceID'] = (string [32]) `230cff041c47c68adfc8bcef7658021b`
   ⬦ $params['description'] = (string [32]) `Online Contribution: memberships`
   ⬦ $params['contributionPageID'] = (string [1]) `2`
   ⬦ $params['contactID'] = (string [1]) `2`
   ⬦ $params['contributionRecurID'] = (int) 10
   ▾ $params['createdMembershipIDs'] = (array [1])
     ⬦ $params['createdMembershipIDs'][0] = (string [1]) `2`
   ⬦ $params['membershipID'] = (string [1]) `2`
   ⬦ $params['contributionID'] = (int) 12
   */
  public function doTransferCheckout( &$params, $component ) {
    // Where should the user come back on our site after completing the GoCardless offsite process?
    $url = CRM_Utils_System::url(
      ($component == 'event') ? 'civicrm/event/register' : 'civicrm/contribute/transact',
      "_qf_ThankYou_display=1&qfKey={$params['qfKey']}"."&cid={$params['contactID']}",
      true, null, false );

    try {
      // Get a GoCardless redirect flow URL.
      $redirect_flow = GoCardlessUtils::getRedirectFlow([
        "test_mode"             => (bool) $this->_paymentProcessor['is_test'],
        "description"           => $params['description'],
        "session_token"         => $params['qfKey'],
        "success_redirect_url"  => $url,
      ]);

      // Store some details on the session that we'll need when the user returns from GoCardless.
      // Key these by the redirect flow id just in case the user simultaneously
      // does two things at once in two tabs (??)
      $sesh = CRM_Core_Session::singleton();
      $sesh_store = $sesh->get('redirect_flows', 'GoCardless');
      $sesh_store = $sesh_store ? $sesh_store : [];
      $sesh_store[$redirect_flow->id] = [];
      foreach (['contributionID', 'contributionRecurID', 'contactID'] as $_) {
        if (!empty($params[$_])) {
          $sesh_store[$redirect_url][$_] = $params[$_];
        }
      }
      $sesh->set('redirect_flows', $sesh_store, 'GoCardless');

      // Redirect user.
      CRM_Utils_System::redirect($redirect_url);
    }
    catch (\Exception $e) {
      CRM_Core_Session::setStatus('Sorry, there was an error contacting the payment processor GoCardless.', ts("Error"), "error");
      CRM_Utils_System::redirect($params['entryURL']);
    }
  }

  public function xhandlePaymentNotification() {
    CRM_Core_Error::debug_log_message( 'uk_co_vedaconsulting_payment_smartdebitdd handlePaymentNotification' );
    CRM_Core_Error::debug_log_message( '$_GET[]:'  . print_r( $_GET, true ) );
    CRM_Core_Error::debug_log_message( '$_POST[]:' . print_r( $_POST, true ) );

    CRM_Core_Error::debug( 'Smart Debit handlePaymentNotification');

    require_once 'CRM/Utils/Array.php';
    require_once 'CRM/Core/Payment/SmartDebitIPN.php';

    $module = CRM_Utils_Array::value( 'module', $_GET );
    if ( empty( $_GET ) ) {
        $rpInvoiceArray = array();
        $rpInvoiceArray = explode( '&' , $_POST['rp_invoice_id'] );
        foreach ( $rpInvoiceArray as $rpInvoiceValue ) {
            $rpValueArray = explode ( '=' , $rpInvoiceValue );
            if ( $rpValueArray[0] == 'm' ) {
                $value = $rpValueArray[1];
            }
        }
        CRM_Core_Error::debug_log_message('uk_co_vedaconsulting_payment_smartdebitdd handlePaymentNotification #2');

        $SmartDebitIPN = new CRM_Core_Payment_SmartDebitIPN();
    } else {
        CRM_Core_Error::debug_log_message('uk_co_vedaconsulting_payment_smartdebitdd handlePaymentNotification #3');
        $value         = CRM_Utils_Array::value( 'module', $_GET );
        $SmartDebitIPN = new CRM_Core_Payment_SmartDebitIPN();
    }
    CRM_Core_Error::debug_log_message('uk_co_vedaconsulting_payment_smartdebitdd handlePaymentNotification value='.$value);

    switch ( strtolower( $value ) ) {
        case 'contribute':
            $SmartDebitIPN->main( 'contribute' );
            break;
        case 'event':
            $SmartDebitIPN->main( 'event' );
            break;
        default     :
            require_once 'CRM/Core/Error.php';
            CRM_Core_Error::debug_log_message( "Could not get module name from request url" );
            echo "Could not get module name from request url<p>";
            break;
    }
  }

  function xgocardless_dd_civicrm_xmlMenu( &$files ) {
    $files[] = dirname(__FILE__)."/xml/Menu/CustomTestForm.xml";
  }
}
