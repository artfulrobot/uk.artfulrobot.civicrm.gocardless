<?php

/**
 * @file
 * Payment Processor for GoCardless.
 */
use CRM_GoCardless_ExtensionUtil as E;
use Civi\Payment\Exception\PaymentProcessorException;

/**
 *
 */
class CRM_Core_Payment_GoCardless extends CRM_Core_Payment {

  /**
   * @var bool TRUE if test mode.  */
  protected $test_mode;

  /**
   * @var Array of \GoCardlessPro\Client objects keyed by payment processor id.
   */
  protected static $gocardless_api;

  /**
   * Fields that affect the schedule and are defined as editable by the processor.
   *
   * This is deliberately blank; for now we only suport changing the amount.
   *
   * @var array
   */
  protected $editableScheduleFields = [];

  /**
   * Constructor
   *
   * @param string $mode the mode of operation: live or test
   * @param $paymentProcessor
   */
  public function __construct($mode, &$paymentProcessor) {
    $this->test_mode = ($mode === 'test');
    $this->_paymentProcessor = $paymentProcessor;
    // ? $this->_processorName    = E::ts('GoCardless Processor');
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
      $errors[] = E::ts("Missing %1", [1 => $this->_paymentProcessor['api.payment_processor_type.getsingle']['user_name_label']]);
    }
    if (empty($this->_paymentProcessor['url_api'])) {
      $errors[] = E::ts("Missing URL for API. This sould probably be %1 (for live payments) or %2 (for test/sandbox)",
        [
          1 => $this->_paymentProcessor['api.payment_processor_type.getsingle']['url_api_default'],
          2 => $this->_paymentProcessor['api.payment_processor_type.getsingle']['url_api_test_default'],
        ]);
    }

    if (!empty($errors)) {
      $errors = "<ul><li>" . implode('</li><li>', $errors) . "</li></ul>";
      CRM_Core_Session::setStatus($errors, E::ts('Error'), 'error');
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
    //$form->add('select', 'preferred_collection_day', E::ts('Preferred Collection Day'), $collectionDaysArray, FALSE);
  }

  /**
   * Attempt to cancel the subscription at GoCardless.
   *
   * @see supportsCancelRecurring()
   *
   * @param string $message
   * @param array $params
   * which refers to 'subscriptionId'
   *
   * @return bool
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function cancelSubscription(&$message = '', $params = []) {
    if (empty($params['subscriptionId'])) {
      throw new PaymentProcessorException("cancelSubscription requires a subscriptionId");
    }
    // Get the GoCardless subscription ID, stored in processor_id
    $contrib_recur = civicrm_api3('ContributionRecur', 'getsingle', ['processor_id' => $params['subscriptionId']]);
    $subscription_id = $contrib_recur['processor_id'];

    $log_message = __FUNCTION__ . ": "
    . json_encode(['subscription_id' => $subscription_id, 'contribution_recur_id' => $params['id']])
    . ' ';
    try {
      $gc_api = $this->getGoCardlessApi();
      $gc_api->subscriptions()->cancel($subscription_id);
      CRM_Core_Error::debug_log_message("$log_message SUCCESS", FALSE, 'GoCardless', PEAR_LOG_INFO);
    }
    catch (\GoCardlessPro\Core\Exception\ApiException $e) {
      // Api request failed / record couldn't be created.
      $this->logGoCardlessException("$log_message FAILED", $e);
      // repackage as PaymentProcessorException
      throw new PaymentProcessorException($e->getMessage());

    }
    catch (\GoCardlessPro\Core\Exception\MalformedResponseException $e) {
      // Unexpected non-JSON response
      $this->logGoCardlessException("$log_message FAILED", $e);
      throw new PaymentProcessorException('Unexpected response type from GoCardless');

    }
    catch (\GoCardlessPro\Core\Exception\ApiConnectionException $e) {
      // Network error
      $this->logGoCardlessException("$log_message FAILED", $e);
      throw new PaymentProcessorException('Network error, please try later.');
    }

    $message = "Successfully cancelled the subscription at GoCardless.";
    return TRUE;
  }

  /**
   * Attempt to change the subscription at GoCardless.
   *
   * Note there are some limitations here:
   *
   * - We can only make 10 changes before we need to cancel the subscription
   *   and create a new one. The latter is not implemented.
   * - We can only change the amount (not frequency etc)
   *
   * @param string $message
   * @param array $params
   *
   * - id: ContributionRecur ID
   * - amount: new amount
   *
   * The following may be received from CiviCRM's
   * CRM_Contribute_Form_UpdateSubscription but are not required/used.
   *
   * - subscriptionId: This is the value of ContributionRecur.processor_id
   *   which (as of v1.9) relates to the GoCardless subscription ID.
   * - is_notify: 0|1 whether to notify the user (not implemented here)
   * - installments: new No. installments (can be blank)
   * - (campaign_id): only present if we support that
   * - (financial_type_id): only present if we support changing that
   * - custom ContributionRecur data fields
   * - ? entityID, if it's there it belongs to ContributionRecur
   * - Plus any fields defined in $editableScheduleFields.
   *
   * @return array|bool|object
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function changeSubscriptionAmount(&$message = '', $params = []) {
    // Get the GoCardless subscription ID, stored in processor_id
    $contrib_recur = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $params['id']]);
    $subscription_id = $contrib_recur['processor_id'] ?? NULL;
    if (!$subscription_id) {
      throw new PaymentProcessorException("Missing GoCardless subscription ID in ContributionRecur processor_id field. Cannot process changing subscription amount.");
    }

    if (empty($params['amount']) || ((float) $params['amount']) < 0.01) {
      throw new PaymentProcessorException("Missing/invalid amount");
    }
    if ($params['amount'] == $contrib_recur['amount']) {
      throw new PaymentProcessorException("The given amount is the same as the current amount. Refusing to update subscription without a change in amount.");
    }

    if (!empty($params['installments'])) {
      throw new PaymentProcessorException("This processor does not support changing the number of installments.");
    }

    $subscription_updates = [
      'params' => [
    // Convert to pennies.
        'amount' => (int) (100 * $params['amount']),
      ],
    ];

    $log_message = __FUNCTION__ . ": "
    . json_encode(['subscription_id' => $subscription_id, 'contribution_recur_id' => $params['id']])
    . ' ';
    try {
      $gc_api = $this->getGoCardlessApi();
      $gc_api->subscriptions()->update($subscription_id, $subscription_updates);
      CRM_Core_Error::debug_log_message("$log_message SUCCESS", FALSE, 'GoCardless', PEAR_LOG_INFO);
    }
    catch (\GoCardlessPro\Core\Exception\ApiException $e) {
      // Api request failed / record couldn't be created.
      $this->logGoCardlessException("$log_message FAILED", $e);
      // repackage as PaymentProcessorException
      throw new PaymentProcessorException($e->getMessage());

    }
    catch (\GoCardlessPro\Core\Exception\MalformedResponseException $e) {
      // Unexpected non-JSON response
      $this->logGoCardlessException("$log_message FAILED", $e);
      throw new PaymentProcessorException('Unexpected response type from GoCardless');

    }
    catch (\GoCardlessPro\Core\Exception\ApiConnectionException $e) {
      // Network error
      $this->logGoCardlessException("$log_message FAILED", $e);
      throw new PaymentProcessorException('Network error, please try later.');
    }

    $message = "Successfully updated regular amount to $params[amount].";
    return TRUE;
  }

  /**
   * The only implementation is sending people off-site using doTransferCheckout.
   */
  public function doDirectPayment(&$params) {
    CRM_Core_Error::fatal(E::ts('This function is not implemented'));
  }

  /**
   * Sends user off to Gocardless.
   *
   * Note: the guts of this function are in doTransferCheckoutWorker() so that
   * can be tested without issuing a redirect.
   *
   * This is called by civicrm_api3_contribution_transact calling doPayment on the payment processor.
   */
  public function doTransferCheckout(&$params, $component) {
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
   * @param mixed $component
   *
   * @return string
   *   URL to redirec to.
   */
  public function doTransferCheckoutWorker(&$params, $component) {

    try {
      // Get a GoCardless redirect flow URL.
      $redirect_params = $this->getRedirectParametersFromParams($params, $component);
      $redirect_flow = $this->getRedirectFlow($redirect_params);

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
      CRM_Core_Session::setStatus(E::ts('Sorry, there was an error contacting the payment processor GoCardless.'), E::ts("Error"), "error");
      CRM_Core_Error::debug_log_message('CRM_Core_Payment_GoCardless::doTransferCheckoutWorker exception: ' . $e->getMessage() . "\n\n" . $e->getTraceAsString(), FALSE, 'GoCardless', PEAR_LOG_ERR);
      return $params['entryURL'];
    }
  }

  /**
   * Create the inputs for creating a GoCardless redirect flow from the CiviCRM provided parameters.
   *
   * Name, address, phone, email parameters provided by profiles have names like:
   *
   * - email-5 (5 is the LocationType ID)
   * - email-Primary (Primary email was selected)
   *
   * We try to pick the billing location types if possible, after that we look
   * for Primary, after that we go with any given.
   *
   * @see https://developer.gocardless.com/api-reference/#core-endpoints-redirect-flows
   *
   * @param array $params
   * @param string $component ("event"|"contribute")
   */
  public function getRedirectParametersFromParams($params, $component) {
    // Where should the user come back on our site after completing the GoCardless offsite process?
    $url = CRM_Utils_System::url(
      ($component == 'event') ? 'civicrm/event/register' : 'civicrm/contribute/transact',
      "_qf_ThankYou_display=1&qfKey={$params['qfKey']}" . "&cid={$params['contactID']}",
      TRUE, NULL, FALSE);

    $redirect_params = [
      "test_mode"            => (bool) $this->_paymentProcessor['is_test'],
      "session_token"        => $params['qfKey'],
      "success_redirect_url" => $url,
      "description"          => isset($params['description']) ? $params['description'] : NULL,
    ];

    // Check for things we can pre-fill.
    $customer = [];
    $emails = [];
    $addresses = [];
    foreach ($params as $civi_prop => $value) {
      if ($civi_prop === 'first_name') {
        $customer['given_name'] = $value;
      }
      elseif ($civi_prop === 'last_name') {
        $customer['family_name'] = $value;
      }
      elseif (preg_match('/^email-(\d)+$/', $civi_prop, $matches)) {
        $emails[$matches[1]] = $value;
      }
      elseif (preg_match('/^(street_address|city|postal_code|country|state_province)-(\d|\w+)+$/', $civi_prop, $matches)) {
        $addresses[$matches[2]][$matches[1]] = $value;
      }
    }

    // First choice is 'billing'.
    $preferences = [];
    $billing_location_type_id = CRM_Core_BAO_LocationType::getBilling();
    if ($billing_location_type_id) {
      $preferences[] = $billing_location_type_id;
    }
    // Second choice is 'Primary'.
    $preferences[] = 'Primary';

    /**
     * Sugar for finding a preferred value, in case there are two.
     *
     * @param array $prefs array of preferences, like [5, 'Primary']
     * @param array $data array to search in.
     * @return mixed Best preference value from $data array. Or NULL.
     */
    function select_by_preference($prefs, $data) {
      $selected = NULL;
      if ($data) {
        // Fallback preference.
        $prefs[] = array_keys($data)[0];

        foreach ($prefs as $type) {
          if (isset($data[$type])) {
            $selected = $data[$type];
            break;
          }
        }
      }
      return $selected;
    }

    $_ = select_by_preference($preferences, $addresses);
    if ($_) {
      // We have an address, use it.
      if (isset($_['street_address'])) {
        $customer['address_line1'] = $_['street_address'];
      }
      if (isset($_['city'])) {
        $customer['city'] = $_['city'];
      }
      if (isset($_['postal_code'])) {
        $customer['postal_code'] = $_['postal_code'];
      }
      if (isset($_['country'])) {
        // We need an ISO 3166-1 alpha-2 version of the country, not the CiviCRM country ID.
        $customer['country_code'] = CRM_Core_PseudoConstant::countryIsoCode($_['country']);
      }
    }

    // If we have an email, use it.
    $_ = select_by_preference($preferences, $emails);
    if ($_) {
      $customer['email'] = $_;
    }

    if ($customer) {
      $redirect_params['prefilled_customer'] = $customer;
    }
    return $redirect_params;
  }

  /**
   * Sets up a redirect flow with GoCardless.
   *
   * @param Array $deets has the following keys:
   * - description          string what is the person signing up to/buying?
   * - session_token        string required by GoCardless to verify that the completion happens by the same user.
   * - success_redirect_url string URL on our site that GoCardless will issue a redirect to on success.
   *
   * @return \GoCardlessPro\Resources\RedirectFlow
   */
  public function getRedirectFlow($deets) {

    foreach (['session_token', 'success_redirect_url', 'description'] as $_) {
      if (empty($deets[$_])) {
        throw new InvalidArgumentException("Missing $_ passed to CRM_Core_Payment_GoCardless::getRedirectFlow.");
      }
      $params[$_] = $deets[$_];
    }

    // Copy optional parameters, if we have them.
    if (!empty($deets['prefilled_customer'])) {
      $params['prefilled_customer'] = $deets['prefilled_customer'];
    }

    $gc_api = $this->getGoCardlessApi();
    /** @var \GoCardlessPro\Resources\RedirectFlow $redirect_flow */
    $redirect_flow = $gc_api->redirectFlows()->create(["params" => $params]);

    return $redirect_flow;
  }

  /**
   * Returns a GoCardless API object for this payment processor.
   *
   * These are cached not because they are expensive to create, but to allow
   * testing.  Nb. this may be injected by setGoCardlessApi() for testing.
   *
   * @return \GoCardlessPro\Client
   */
  public function getGoCardlessApi() {
    $pp = $this->getPaymentProcessor();
    if (!isset(static::$gocardless_api[$pp['id']])) {
      $access_token = $pp['user_name'];
      CRM_GoCardlessUtils::loadLibraries();
      static::$gocardless_api[$pp['id']] = new \GoCardlessPro\Client(array(
        'access_token' => $access_token,
        'environment'  => $pp['is_test'] ? \GoCardlessPro\Environment::SANDBOX : \GoCardlessPro\Environment::LIVE,
      ));
    }
    return static::$gocardless_api[$pp['id']];
  }

  /**
   * Returns a GoCardless API object for this payment processor.
   *
   * @param \GoCardlessPro\Client|null $mocked_api pass NULL to remove cache.
   */
  public function setGoCardlessApi($mocked_api) {
    $pp = $this->getPaymentProcessor();
    if ($mocked_api === NULL) {
      unset(static::$gocardless_api[$pp['id']]);
    }
    else {
      static::$gocardless_api[$pp['id']] = $mocked_api;
    }
  }

  /**
   * Shared code to handle extracting info from a gocardless exception.
   * @see https://github.com/gocardless/gocardless-pro-php/
   */
  protected function logGoCardlessException($prefix, $e) {
    CRM_Core_Error::debug_log_message(
          "$prefix" . json_encode([
            'message' => $e->getMessage(),
            'type' => $e->getType(),
            'errors' => $e->getErrors(),
            'requestId' => $e->getRequestId(),
            'documentationUrl' => $e->getDocumentationUrl(),
          ], JSON_PRETTY_PRINT),
        FALSE, 'GoCardless', PEAR_LOG_INFO);
  }

  /**
   * Does this processor support cancelling recurring contributions through code.
   *
   * Since v1.9 we do because we now use processor_id instead of trxn_id to
   * match subscription_id. For a while we were waiting on
   * https://github.com/civicrm/civicrm-core/pull/15673
   * but decided just to start using processor_id anyway.
   *
   * @return bool
   */
  protected function supportsCancelRecurring() {
    return TRUE;
  }

  /**
   * Shorthand method to determine if this processor is a test one.
   */
  public function isTestMode() {
    $pp = $this->getPaymentProcessor();
    return $pp['is_test'];
  }

}
