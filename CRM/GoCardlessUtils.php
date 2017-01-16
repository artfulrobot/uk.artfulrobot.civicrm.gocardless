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

    foreach (['session_token', 'success_redirect_url', 'description'] as $_) {
      if (empty($deets[$_])) {
        throw new InvalidArgumentException("Missing $_ passed to CRM_GoCardlessUtils::getRedirectFlow.");
      }
      $params[$_] = $deets[$_];
    }
    if (!isset($deets['test_mode'])) {
      throw new InvalidArgumentException("Missing test_mode passed to CRM_GoCardlessUtils::getRedirectFlow.");
    }

    $gc_api = CRM_GoCardlessUtils::getApi($deets['test_mode']);
    /** @var \GoCardlessPro\Resources\RedirectFlow $redirect_flow */
    $redirect_flow = $gc_api->redirectFlows()->create(["params" => $params]);

    return $redirect_flow;
  }
  /**
   * Complete a GoCardless redirect flow and update CiviCRM.
   *
   * 1. Basic input Validation.
   * 2. 'complete the flow' with GoCardless.
   * 3. Create a subscription.
   * 4. Update contribution, contribution_recur, membership tables.
   *
   * If anything goes wrong cancel things.
   *
   * @var array $deets with the following mandatory keys:
   *
   * - test_mode bool.
   * - session_token string used in creating the flow with getRedirectFlow().
   * - contributionID int
   * - contactID int
   * - description
   *
   * and the following optional ones:
   *
   * - contributionRecurID int
   * - membershipID int
   * - dayOfMonth int (defaults to 1)
   *
   * @return void
   */
  public static function completeRedirectFlow($deets)
  {
    try {
      // Validate input.
      foreach (['redirect_flow_id', 'test_mode', 'session_token', 'contributionID', 'contactID'] as $_) {
        if (empty($deets[$_])) {
          throw new InvalidArgumentException("Missing $_ passed to CRM_GoCardlessUtils::getRedirectFlow.");
        }
        $params[$_] = $deets[$_];
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

      // We need to know the interval.
      // This comes from the membership record or the recurring contribution record.
      if (!empty($deets['contributionRecurID'])) {
        // Load interval details from the recurring contribution record.
        $result = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $deets['contributionRecurID']]);
        $interval_unit = $result['frequency_unit'];
        $interval_interval = $result['frequency_interval'];
        $amount = $result['amount'];
      }
      else if (!empty($deets['membershipID'])) {
        // This is a membership. Load the interval from the type.
        $result = civicrm_api3('Membership', 'getsingle',
          ['id' => $deets['membershipID'], 'api.MembershipType.getsingle' => []]
        );
        $interval_unit = $result['api.MembershipType.getsingle']['duration_unit'];
        $interval_interval = $result['api.MembershipType.getsingle']['duration_interval'];
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

      if (!in_array($interval_unit, ['year', 'month', 'week'])) {
        // Throw a spanner in the works if interval not supported by Go Cardless.
        // https://developer.gocardless.com/api-reference/#subscriptions-create-a-subscription
        throw new Exception("Invalid interval '$interval_unit', must be year/month/week.");
      }

      // Now create the subscription.
      $subscription = $gc_api->subscriptions()->create(["params" => [
        'amount' => (int) (100 * $amount), // Convert amount to pennies.
        'currency' => 'GBP',
        'name' => $deets['description'],
        'interval_unit' => $interval_unit . 'ly', // year -> yearly etc.
        'links' => ['mandate' => $redirect_flow->links->mandate],
        //'metadata' => ['order_no' => 'ABCD1234'],
      ]]);
    }
    catch (Exception $e) {
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
          'end_date'   => date('Y-m-d', strtotime($subscription->start_date . " + $interval_interval $interval_unit")),
          'start_date' => date('Y-m-d'),
          'join_date'  => date('Y-m-d'),
          'status_id'  => 1,//New
        ]);
      }
    }
    catch (Exception $e) {
      // The Subscription *was* set up but we died updating CiviCRM about it. Disaster, darling.
      // This is not going to be nice.
      CRM_Core_Session::setStatus("Sorry, there was a problem recording the details of your Direct Debit. Please call us.", 'Error', 'error');
    }
  }
}
