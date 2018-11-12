<?php
/**
 * Utils class for GoCardless integration.
 * @author Rich Lott / Artful Robot.
 */

require_once (dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php' );

/**
 * Class CRM_GoCardlessUtils
 */
class CRM_GoCardlessUtils
{
  const GC_TEST_SORT_CODE = '200000';
  const GC_TEST_ACCOUNT   = '55779911';
  /** @var \GoCardlessPro\Client (or mock) with test credentials. */
  protected static $api_test;
  /** @var \GoCardlessPro\Client (or mock) with live credentials. */
  protected static $api_live;

  /**
   * Returns a GoCardless API object.
   *
   * There's a singleton pattern here for each of live/test.
   *
   * @param bool $test Sandbox or Live API?
   * @return \GoCardlessPro\Client
   */
  public static function getApi($test=FALSE)
  {
    if ($test && isset(static::$api_test)) {
      return static::$api_test;
    }
    if (!$test && isset(static::$api_live)) {
      return static::$api_live;
    }

    $pp = CRM_GoCardlessUtils::getPaymentProcessor($test);
    $access_token = $pp['user_name'];

    $client = new \GoCardlessPro\Client(array(
        'access_token' => $access_token,
        'environment'  => $test ? \GoCardlessPro\Environment::SANDBOX : \GoCardlessPro\Environment::LIVE
        ));

    if ($test) {
      static::$api_test = $client;
    }
    else {
      static::$api_live = $client;
    }
    return $client;
  }
  /**
   * Do a PaymentProcessor:getsingle for the GoCardless processor type.
   *
   * @param bool $test Whether to find a test processor or a live one.
   */
  public static function getPaymentProcessor($test=FALSE) {
    // Find the credentials.
    $result = civicrm_api3('PaymentProcessor', 'getsingle',
      ['payment_processor_type_id' => "GoCardless", 'is_test' => (int)$test]);
    return $result;
  }
  /**
   * Sets the live or test GoCardlessPro API.
   *
   * This is useful so you can mock it for tests.
   *
   * @param bool $test
   * @param \GoCardlessPro\Client $api like object.
   * @return void
   */
  public static function setApi($test, \GoCardlessPro\Client $api)
  {
    if (!($api instanceof \GoCardlessPro\Client)) {
      throw new InvalidArgumentException("Object passed to CRM_GoCardlessUtils::setApi does not look like a GoCardlessPro\\Client");
    }
    if ($test) {
      static::$api_test = $api;
    }
    else {
      static::$api_live = $api;
    }
  }
  /**
   * Sets up a redirect flow with GoCardless.
   *
   * @param Array $deets has the following keys:
   * - description          string what is the person signing up to/buying?
   * - session_token        string required by GoCardless to verify that the completion happens by the same user.
   * - success_redirect_url string URL on our site that GoCardless will issue a redirect to on success.
   * - test_mode            bool   whether to use the test credentials or not.
   *
   * @return \GoCardlessPro\Resources\RedirectFlow
   */
  public static function getRedirectFlow($deets) {

    // We need test_mode but it's not part of the params we pass on.
    if (!isset($deets['test_mode'])) {
      throw new InvalidArgumentException("Missing test_mode passed to CRM_GoCardlessUtils::getRedirectFlow.");
    }
    $gc_api = CRM_GoCardlessUtils::getApi($deets['test_mode']);

    // Check for and copy the essential parameters.
    foreach (['session_token', 'success_redirect_url', 'description'] as $_) {
      if (empty($deets[$_])) {
        throw new InvalidArgumentException("Missing $_ passed to CRM_GoCardlessUtils::getRedirectFlow.");
      }
      $params[$_] = $deets[$_];
    }

    // Copy optional parameters, if we have them.
    if (!empty($deets['prefilled_customer'])) {
      $params['prefilled_customer'] = $deets['prefilled_customer'];
    }

    /** @var \GoCardlessPro\Resources\RedirectFlow $redirect_flow */
    $redirect_flow = $gc_api->redirectFlows()->create(["params" => $params]);

    return $redirect_flow;
  }
  /**
   * Complete a GoCardless redirect flow, set up subscription from details given.
   *
   * @var array $deets with the following mandatory keys:
   *
   * - test_mode bool.
   * - session_token string used in creating the flow with getRedirectFlow().
   * - redirect_flow_id
   * - description
   * - interval_unit yearly/monthly/weekly
   * - amount (in GBP, e.g. 10.50)
   * - installments (optional positive integer number of payments to take)
   *
   * @return array with these keys:
   * - gc_api         GoCardless API object used.
   * - redirect_flow  RedirectFlow object
   * - subscription   Subscription object
   */
  public static function completeRedirectFlowWithGoCardless($deets) {
    // Validate input.
    foreach (['redirect_flow_id', 'test_mode', 'session_token', 'description',
      'amount', 'interval_unit',
    ] as $_) {
      if (!isset($deets[$_])) {
        throw new InvalidArgumentException("Missing $_ passed to CRM_GoCardlessUtils::getRedirectFlow.");
      }
    }

    $interval_unit = $deets['interval_unit'];
    $interval = isset($deets['interval']) ? $deets['interval'] : 1;

    // Throw a spanner in the works if interval not supported by Go Cardless.
    // https://developer.gocardless.com/api-reference/#subscriptions-create-a-subscription

    if (!in_array($interval_unit, ['yearly', 'monthly', 'weekly'])) {
      throw new Exception("Invalid interval '$interval_unit', must be yearly/monthly/weekly.");
    }

    // Direct Debits must be at most yearly
    if ($interval_unit == 'yearly' && $interval > 1 ||
        $interval_unit == 'monthly' && $interval > 12 ||
        $interval_unit == 'weekly' && $interval > 52) {
      throw new Exception("Interval must be at most yearly, not $interval $interval_unit");
    }

    // Check installments is positive
    if (isset($deets['installments'])) {
      $_ = (int) $deets['installments'];
      if ($_ <= 0) {
        throw new Exception("Number of payments must be positive, not " . $deets['installments']);
      }
      $installments = $_;
    }

    // 1. Complete the flow.
    // This creates a Customer, Customer Bank Account and Mandate at GC.
    $gc_api = CRM_GoCardlessUtils::getApi($deets['test_mode']);
    $redirect_flow = $gc_api->redirectFlows()->complete($deets['redirect_flow_id'], [
      "params" => ["session_token" => $deets['session_token']],
    ]);

    // 2. Set up subscription at GC.
    // "creditor": "CR123",
    // "mandate": "MD123",
    // "customer": "CU123",
    // "customer_bank_account": "BA123"
    $params = [
      'amount'        => (int) (100 * $deets['amount']), // Convert amount to pennies.
      'currency'      => 'GBP',
      'name'          => $deets['description'],
      'interval'      => $interval,
      'interval_unit' => $interval_unit, // yearly etc.
      'links'         => ['mandate' => $redirect_flow->links->mandate],
      //'metadata' => ['order_no' => 'ABCD1234'],
    ];

    if (isset($installments)) {
      $params['count'] = $installments;
    }
    $subscription = $gc_api->subscriptions()->create(["params" => $params]);

    CRM_Core_Error::debug_log_message(__FUNCTION__ . ": successfully completed redirect flow "
      . $deets['redirect_flow_id']
      . " mandate: {$redirect_flow->links->mandate} subscription: {$subscription->id}", FALSE, 'GoCardless', PEAR_LOG_INFO);

    // Return our objects in case that's helpful.
    return [
        'gc_api' => $gc_api,
        'redirect_flow' => $redirect_flow,
        'subscription' => $subscription,
      ];
  }
  /**
   * Complete the redirect flow as used by the contribution pages.
   *
   * This starts off with the person in the database and we use this data to
   * complete the flow. It's called from gocardless.php buildForm hook when
   * the thank you page would be displayed.
   *
   */
  public static function completeRedirectFlowCiviCore($deets)
  {
    CRM_Core_Error::debug_log_message(__FUNCTION__ . ": called with details: " . json_encode($deets), FALSE, 'GoCardless', PEAR_LOG_INFO);
    try {
      if (empty($deets['contactID'])) {
        throw new InvalidArgumentException("Missing contactID");
      }
      if (empty($deets['contributionID'])) {
        throw new InvalidArgumentException("Missing contributionID");
      }
      if (!empty($deets['contributionRecurID'])) {
        // Load interval details from the recurring contribution record.
        $result = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $deets['contributionRecurID']]);
        $interval_unit = $result['frequency_unit'];
        $interval = $result['frequency_interval'];
        $amount = $result['amount'];

        // Check if limited number of installments.
        if (!empty($result['installments'])) {
          $installments = $result['installments'];
        }

      } elseif (!empty($deets['membershipID'])) {
        // This is a membership. Load the interval from the type.
        $result = civicrm_api3('Membership', 'getsingle',
          ['id' => $deets['membershipID'], 'api.MembershipType.getsingle' => []]
        );
        $interval_unit = $result['api.MembershipType.getsingle']['duration_unit'];
        $interval = $result['api.MembershipType.getsingle']['duration_interval'];
      }
      else {
        // Something is wrong.
        throw new Exception("Failed to find interval details");
      }

      // If we don't have the amount yet, load it from the Contribution record.
      if (!isset($amount)) {
        $result = civicrm_api3('Contribution', 'getsingle', ['id' => $deets['contributionID']]);
        $amount = $result['total_amount'];
      }

      // Now actually do this at GC.
      $params = [
          'interval' => $interval,
          'interval_unit' => $interval_unit . 'ly', // year -> yearly
          'amount' => $amount
      ];

      if (isset($installments)) {
        $params['installments'] = $installments;
      }

      $result = static::completeRedirectFlowWithGoCardless($deets + $params);
      // It's the subscription we're interested in.
      $subscription = $result['subscription'];
    }
    catch (Exception $e) {
      CRM_Core_Error::debug_log_message(__FUNCTION__ . ": EXCEPTION before successfully setting up subscription at GoCardless: " . $e->getMessage() . "\n" . $e->getTraceAsString(), FALSE, 'GoCardless', PEAR_LOG_INFO);
      // Something has gone wrong at this point the chance is that the subscription was not set up.
      // Therefore we should cancel things.
      civicrm_api3('Contribution', 'create', [
        'id' => $deets['contributionID'],
        'contribution_status_id' => 'Failed'
      ]);

      if (!empty($deets['contributionRecurID'])) {
        civicrm_api3('ContributionRecur', 'create', [
          'id' => $deets['contributionRecurID'],
          'contribution_status_id' => 'Failed'
        ]);
      }

      if (!empty($deets['membershipID'])) {
        civicrm_api3('Membership', 'create', [
          'id' => $deets['membershipID'],
          'status_id' => "Cancelled",
        ]);
      }

      CRM_Core_Session::setStatus("Sorry, we were unable to set up your Direct Debit. Please call us.", 'Error', 'error');

      /* I'm not sure this applies to memberships...
      $cancelURL  = CRM_Utils_System::url( 'civicrm/contribute/transact',
                                            "_qf_Main_display=1&cancel=1&qfKey={$_GET['qfKey']}",
                                            true, null, false );
       */

      // Stop processing at this point.
      return;
    }

    // Subscription successfully set up, update CiviCRM.
    try {
      // Update the date of the contribution to the start date returned by GC.
      // We'll leave the payment as 'Pending' though as we haven't had it yet.
      civicrm_api3('Contribution', 'create', [
        'id' => $deets['contributionID'],
        'receive_date' => $subscription->start_date,
      ]);

      if (!empty($deets['contributionRecurID'])) {
        // Update the recurring contribution to In Progress, set the trxn_id and start_date.
        civicrm_api3('ContributionRecur', 'create', [
          'id' => $deets['contributionRecurID'],
          'start_date' => $subscription->start_date,
          'trxn_id' => $subscription->id,
          'contribution_status_id' => "In Progress",
        ]);
      }

      if (!empty($deets['membershipID'])) {
        // Calculate the end date for the membership, although hopefully this will be renewed automatically.
        // People expect their membership to start immediately, although the payment might not come through for a couple of days.
        // Use today's date as the start date, and contribution date + N * interval for end date.
        // Update membership dates.
        civicrm_api3("Membership" ,"create" , [
          'id'         => $deets['membershipID'],
          'end_date'   => date('Y-m-d', strtotime($subscription->start_date . " + $interval $interval_unit")),
          'start_date' => date('Y-m-d'),
          'join_date'  => date('Y-m-d'),
          'status_id'  => 'Pending', // https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/28
        ]);
      }
      CRM_Core_Error::debug_log_message(__FUNCTION__ . ": CiviCRM updated successfully (Contribution ID $deets[contributionID]).", FALSE, 'GoCardless', PEAR_LOG_INFO);
    }
    catch (Exception $e) {
      // The Subscription *was* set up but we died updating CiviCRM about it. Disaster, darling.
      // This is not going to be nice.
      CRM_Core_Error::debug_log_message(__FUNCTION__ . ": EXCEPTION *after* successfully setting up subscription at GoCardless: " . $e->getMessage() . "\n" . $e->getTraceAsString(), FALSE, 'GoCardless', PEAR_LOG_INFO);
      CRM_Core_Session::setStatus("Sorry, there was a problem recording the details of your Direct Debit. Please call us.", 'Error', 'error');
    }
  }
}
