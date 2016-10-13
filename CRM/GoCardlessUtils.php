<?php
/**
 * Utils class for GoCardless integration.
 * @author Rich Lott / Artful Robot.
 */

/**
 * Class GoCardlessUtils
 */
class GoCardlessUtils
{
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

    // Find the credentials.
    $result = civicrm_api3('PaymentProcessor', 'getsingle',
      ['payment_processor_type_id' => "GoCardless", 'is_test' => (int)$test]);
    $access_token = $result['user_name'];

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
      throw new InvalidArgumentException("Object passed to GoCardlessUtils::setApi does not look like a GoCardlessPro\\Client");
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

    foreach (['test_mode', 'session_token', 'success_redirect_url', 'description'] as $_) {
      if (empty($deets[$_])) {
        throw new InvalidArgumentException("Missing $_ passed to GoCardlessUtils::getRedirectFlow.");
      }
      $params[$_] = $deets[$_];
    }

    $gc_api = GoCardlessUtils::getApi($deets['test_mode']);
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
   * - contribtionID int
   * - contactID int
   * - description
   *
   * and the following optional ones:
   *
   * - contribtionRecurID int
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
          throw new InvalidArgumentException("Missing $_ passed to GoCardlessUtils::getRedirectFlow.");
        }
        $params[$_] = $deets[$_];
      }

      // 1. Complete the flow.
      // This creates a Customer, Customer Bank Account and Mandate at GC.
      $gc_api = GoCardlessUtils::getApi($deets['test_mode']);
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
      if (!empty($deets['membershipID'])) {
        // This is a membership. Load the interval from the type.
        $result = civicrm_api3('Membership', 'getsingle',
          ['id' => $deets['membershipID'], 'api.MembershipType.getsingle' => []]
        );
        $interval_unit = $result['api.MembershipType.getsingle']['duration_unit'];
        $interval_interval = $result['api.MembershipType.getsingle']['duration_interval'];
      }
      elseif (!empty($deets['contributionRecurID'])) {
        // Load interval details from the recurring contribution record.
        $result = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $deets['contribtionRecurID']]);
        $interval_unit = $result['frequency_unit'];
        $interval_interval = $result['frequency_interval'];
        $amount = $result['amount'];
      }
      else {
        // Something is wrong.
        throw new Exception("Failed to find interval details");
      }

      if (!in_array($interval_unit, ['year', 'month', 'week'])) {
        // Throw a spanner in the works if interval not supported by Go Cardless.
        // https://developer.gocardless.com/api-reference/#subscriptions-create-a-subscription
        throw new Exception("Invalid interval '$interval_unit', must be year/month/week.");
      }

      // Now create the subscription.
      $response = $api->subscriptions()->create(["params" => [
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
        'id' => $deets['contribtionID'],
        'contribution_status_id' => 'Failed'
      ]);

      if (!empty($deets['contribtionRecurID'])) {
        civicrm_api3('ContributionRecur', 'create', [
          'id' => $deets['contribtionRecurID'],
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
        'id' => $deets['contribtionID'],
        'receive_date' => $response->subscriptions->start_date,
        'invoice_id' => $response->subscriptions->id,
      ]);

      if (!empty($deets['contribtionRecurID'])) {
        // Update the recurring contribution to In Progress, set the invoice_id and start_date.
        civicrm_api3('ContributionRecur', 'create', [
          'id' => $deets['contribtionRecurID'],
          'start_date' => $response->subscriptions->start_date,
          'invoice_id' => $response->subscriptions->id,
          'contribution_status_id' => "In Progress",
        ]);
      }

      if (!empty($deets['membershipID'])) {
        // Calculate the end date for the membership, although hopefully this will be renewed automatically.
        // People expect their membership to start immediately, although the payment might not come through for a couple of days.
        // Use today's date as the start date, and contribution date + N * interval for end date.
        $membershipEndDateString = date("Y-m-d",strtotime(date("Y-m-d", strtotime($start_date)) . " +$interval_duration $interval_unit_civi_format"));
        // Update membership dates.
        civicrm_api("Membership" ,"create" , [
          'id'         => $deets['membershipID'],
          'end_date'   => date('Y-m-d', strtotime($response->subscriptions->start_date . " + $interval_interval $interval_unit")),
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
