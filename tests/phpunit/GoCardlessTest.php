<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use Prophecy\Prophet;
use Prophecy\Argument;
use CRM_GoCardless_ExtensionUtil as E;

/**
 * Tests the GoCardless direct debit extension.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class GoCardlessTest extends PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  protected $prophet;
  /**
   * @var array Holds a map of name -> value for contribution recur statuses */
  protected $contribution_recur_status_map;
  /**
   * @var array Holds a map of name -> value for contribution statuses */
  protected $contribution_status_map;
  /**
   * Holds test mode payment processor.
   * @var array
   */
  public $test_mode_payment_processor;

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();

    $this->prophet = new Prophet();

    // Set up a Payment Processor that uses GC.

    $result = civicrm_api3('PaymentProcessor', 'create', array(
      'sequential' => 1,
      'payment_processor_type_id' => "GoCardless",
      'name' => "GoCardless",
      'description' => "Set up by test script",
      'signature' => "mock_webhook_key",
      'is_active' => 1,
      'is_test' => 1,
      'url_api' => 'https://api-sandbox.gocardless.com/',
      'user_name' => "fake_test_api_key",
      'payment_instrument_id' => "direct_debit_gc",
      'domain_id' => 1,
    ));
    $this->test_mode_payment_processor = $result['values'][0];

    // We need a live one, too.
    $result = civicrm_api3('PaymentProcessor', 'create', array(
      'sequential' => 1,
      'payment_processor_type_id' => "GoCardless",
      'name' => "GoCardless",
      'signature' => "this is no the webhook key you are looking fo",
      'description' => "Set up by test script",
      'is_active' => 1,
      'url_api' => 'https://api.gocardless.com/',
      'is_test' => 0,
      'user_name' => "fake_live_api_key",
      'payment_instrument_id' => "direct_debit_gc",
      'domain_id' => 1,
    ));

    // Map contribution statuses to values.
    $this->contribution_recur_status_map = array_flip(CRM_Contribute_BAO_ContributionRecur::buildOptions('contribution_status_id', 'validate'));
    $this->contribution_status_map = array_flip(CRM_Contribute_BAO_Contribution::buildOptions('contribution_status_id', 'validate'));

    // Create a membership type
    $result = civicrm_api3('MembershipType', 'create', [
      'member_of_contact_id' => 1,
      'financial_type_id' => "Member Dues",
      'duration_unit' => "year",
      'duration_interval' => 1,
      'period_type' => "rolling",
      'name' => "MyMembershipType",
      'minimum_fee' => 50,
      'auto_renew' => 1,
    ]);

    $this->membership_status_map = array_flip(CRM_Member_PseudoConstant::membershipstatus());
  }

  public function tearDown() {
    $this->prophet->checkPredictions();
    parent::tearDown();
  }

  /**
   * Check a transfer checkout works.
   *
   * This actually results in a redirect, but all the work that goes into that
   * is in a separate function, so we can test that.
   */
  public function testTransferCheckout() {
    // Mock the GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');

    $redirect_flows = $this->prophet->prophesize('\\GoCardlessPro\\Services\\RedirectFlowsService');
    $api_prophecy->redirectFlows()->willReturn($redirect_flows->reveal());
    $redirect_flows->create(Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"redirect_url":"https://gocardless.com/somewhere","id":"RE1234"}'));

    $pp = $this->test_mode_payment_processor;

    $obj = new CRM_Core_Payment_GoCardless('test', $pp);
    $params = [
      'qfKey' => 'aabbccdd',
      'contactID' => 111,
      'description' => 'test contribution',
      'contributionID' => 222,
      'contributionRecurID' => 333,
      'payment_processor_id' => $this->test_mode_payment_processor['id'],
      'entryURL' => 'http://example.com/somwhere',
    ];
    $obj->setGoCardlessApi($api_prophecy->reveal());
    $url = $obj->doTransferCheckoutWorker($params, 'contribute');
    $this->assertInternalType('string', $url);
    $this->assertNotEmpty('string', $url);
    $this->assertEquals("https://gocardless.com/somewhere", $url);

    // Check inputs for the next stage are stored on the session.
    $sesh = CRM_Core_Session::singleton();
    $sesh_store = $sesh->get('redirect_flows', 'GoCardless');
    $this->assertArrayHasKey('RE1234', $sesh_store);
    $this->assertEquals(TRUE, $sesh_store['RE1234']['test_mode']);
    $this->assertEquals($pp['id'], $sesh_store['RE1234']['payment_processor_id']);
    $this->assertEquals('test contribution', $sesh_store['RE1234']['description']);
    $this->assertEquals(222, $sesh_store['RE1234']['contributionID']);
    $this->assertEquals(333, $sesh_store['RE1234']['contributionRecurID']);
    $this->assertEquals(111, $sesh_store['RE1234']['contactID']);
  }

  /**
   * This creates a contact with a contribution and a ContributionRecur in the
   * same way that CiviCRM's core Contribution Pages form does, then, having
   * mocked the GC API it calls
   * CRM_GoCardlessUtils::completeRedirectFlowCiviCore()
   * and checks that the result is updated contribution and ContributionRecur records.
   *
   * testing with no membership
   */
  public function testTransferCheckoutCompletesWithoutInstallments() {
    // We need to mimick what the contribution page does, which AFAICS does:
    // - Creates a Recurring Contribution
    $contact = civicrm_api3('Contact', 'create', array(
      'sequential' => 1,
      'contact_type' => "Individual",
      'first_name' => "Wilma",
      'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
      'sequential' => 1,
      'contact_id' => $contact['id'],
      'frequency_interval' => 1,
      'amount' => 1,
      'frequency_unit' => "month",
      'start_date' => "2016-10-01",
      'is_test' => 1,
      'contribution_status_id' => "Pending",
    ));
    $contrib = civicrm_api3('Contribution', 'create', array(
      'sequential' => 1,
    // Donation
      'financial_type_id' => 1,
      'total_amount' => 1,
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => "Pending",
      'is_test' => 1,
    ));

    // Mock the GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    $this->mockGoCardlessApiForTestPaymentProcessor($api_prophecy->reveal());

    $redirect_flows_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\RedirectFlowsService');
    $api_prophecy->redirectFlows()->willReturn($redirect_flows_service->reveal());
    $redirect_flows_service->complete(Argument::any(), Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"redirect_url":"https://gocardless.com/somewhere","id":"RE1234","links":{"mandate":"MANDATEID"}}'));

    $subscription_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
    $api_prophecy->subscriptions()->willReturn($subscription_service->reveal());
    $subscription_service->create(Argument::any())
      ->willReturn(json_decode('{"start_date":"2016-10-08","id":"SUBSCRIPTION_ID"}'));
    // Params are usually assembled by the civicrm_buildForm hook.
    $params = [
      'test_mode' => TRUE,
      'redirect_flow_id' => 'RE1234',
      'session_token' => 'aabbccdd',
      'contactID' => $contact['id'],
      'description' => 'test contribution',
      'contributionID' => $contrib['id'],
      'contributionRecurID' => $recur['id'],
      'payment_processor_id' => $this->test_mode_payment_processor['id'],
      'entryURL' => 'http://example.com/somwhere',
    ];
    CRM_GoCardlessUtils::completeRedirectFlowCiviCore($params);

    // Now test the contributions were updated.
    $result = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $recur['id']]);
    $this->assertEquals(5, $result['contribution_status_id']);
    $this->assertEquals('SUBSCRIPTION_ID', $result['trxn_id']);
    $this->assertEquals('2016-10-08 00:00:00', $result['start_date']);
    $result = civicrm_api3('Contribution', 'getsingle', ['id' => $contrib['id']]);
    $this->assertEquals('2016-10-08 00:00:00', $result['receive_date']);
    $this->assertEquals(2, $result['contribution_status_id']);

  }

  /**
   * Check a transfer checkout works.
   *
   * This creates a Contact with a Contribution, a ContributionRecur and a Membership in the
   * same way that CiviCRM's core Contribution Pages form does, then, having
   * mocked the GC API it calls
   * CRM_GoCardlessUtils::completeRedirectFlowCiviCore()
   * and checks that the result is updated contribution and ContributionRecur records.
   *
   * Testing with a new Membership
   */
  public function testTransferCheckoutCompletesWithoutInstallmentsNewMembership() {
    // We need to mimick what the contribution page does, which AFAICS does:
    // - Creates a Recurring Contribution
    $contact = civicrm_api3('Contact', 'create', array(
      'sequential' => 1,
      'contact_type' => "Individual",
      'first_name' => "Wilma",
      'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
      'sequential' => 1,
      'contact_id' => $contact['id'],
      'frequency_interval' => 1,
      'amount' => 1,
      'frequency_unit' => "month",
      'start_date' => "2016-10-01",
      'is_test' => 1,
      'contribution_status_id' => "Pending",
    ));
    $contrib = civicrm_api3('Contribution', 'create', array(
      'sequential' => 1,
    // Donation
      'financial_type_id' => 1,
      'total_amount' => 1,
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => "Pending",
      'is_test' => 1,
    ));
    $membership = civicrm_api3('Membership', 'create', [
      'sequential' => 1,
      'membership_type_id' => 'MyMembershipType',
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'status_id' => "Pending",
    // Needed to override default status calculation
      'skipStatusCal' => 1,
    ]);
    // The dates returned by create and get are formatted differently!
    // So do a get here to make later comparison easier
    $membership = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);

    $membershipPayment = civicrm_api3('MembershipPayment', 'create', [
      'sequential' => 1,
      'membership_id' => $membership['id'],
      'contribution_id' => $contrib['id'],
    ]);

    // Mock the GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    $this->mockGoCardlessApiForTestPaymentProcessor($api_prophecy->reveal());

    $redirect_flows_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\RedirectFlowsService');
    $api_prophecy->redirectFlows()->willReturn($redirect_flows_service->reveal());
    $redirect_flows_service->complete(Argument::any(), Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"redirect_url":"https://gocardless.com/somewhere","id":"RE1234","links":{"mandate":"MANDATEID"}}'));

    $subscription_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
    $api_prophecy->subscriptions()->willReturn($subscription_service->reveal());
    $subscription_service->create(Argument::any())
      ->willReturn(json_decode('{"start_date":"2016-10-08","id":"SUBSCRIPTION_ID"}'));
    // Params are usually assembled by the civicrm_buildForm hook.
    $params = [
      'test_mode' => TRUE,
      'redirect_flow_id' => 'RE1234',
      'session_token' => 'aabbccdd',
      'contactID' => $contact['id'],
      'description' => 'test contribution',
      'contributionID' => $contrib['id'],
      'contributionRecurID' => $recur['id'],
      'membershipID' => $membership['id'],
      'payment_processor_id' => $this->test_mode_payment_processor['id'],
      'entryURL' => 'http://example.com/somwhere',
    ];
    CRM_GoCardlessUtils::completeRedirectFlowCiviCore($params);

    // Now test the contributions were updated.
    $result = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $recur['id']]);
    $this->assertEquals(5, $result['contribution_status_id']);
    $this->assertEquals('SUBSCRIPTION_ID', $result['trxn_id']);
    $this->assertEquals('2016-10-08 00:00:00', $result['start_date']);
    $result = civicrm_api3('Contribution', 'getsingle', ['id' => $contrib['id']]);
    $this->assertEquals('2016-10-08 00:00:00', $result['receive_date']);
    $this->assertEquals(2, $result['contribution_status_id']);
    $result = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);
    // status should be still be Pending
    $this->assertEquals($this->membership_status_map["Pending"], $result['status_id']);
    // Dates should be unchanged
    foreach (['start_date', 'end_date', 'join_date'] as $date) {
      $this->assertEquals($membership[$date], $result[$date]);
    }
  }

  /**
   * Mostly the same as testTransferCheckoutCompletesWithoutInstallmentsNewMembership()
   * but tests with an existing Current Membership - renewal is via GC but previous payments were not.
   */
  public function testTransferCheckoutCompletesWithoutInstallmentsExistingCurrentMembership() {
    // We need to mimick what the contribution page does, which AFAICS does:
    // - Creates a Recurring Contribution
    $contact = civicrm_api3('Contact', 'create', array(
      'sequential' => 1,
      'contact_type' => "Individual",
      'first_name' => "Wilma",
      'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
      'sequential' => 1,
      'contact_id' => $contact['id'],
      'frequency_interval' => 1,
      'amount' => 1,
      'frequency_unit' => "month",
      'start_date' => "2016-10-01",
      'is_test' => 1,
      'contribution_status_id' => "Pending",
    ));
    $contrib = civicrm_api3('Contribution', 'create', array(
      'sequential' => 1,
    // Donation
      'financial_type_id' => 1,
      'total_amount' => 1,
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => "Pending",
      'is_test' => 1,
    ));
    // Mock existing membership
    $dt = new DateTimeImmutable();
    $membership = civicrm_api3('Membership', 'create', [
      'sequential' => 1,
      'membership_type_id' => 'MyMembershipType',
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'status_id' => "Current",
    // Needed to override default status calculation
      'skipStatusCal' => 1,
      'start_date' => $dt->modify("-11 months")->format("Y-m-d"),
      'join_date' => $dt->modify("-23 months")->format("Y-m-d"),
    ]);
    // The dates returned by create and get are formatted differently!
    // So do a get here to make later comparison easier
    $membership = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);

    $membershipPayment = civicrm_api3('MembershipPayment', 'create', [
      'sequential' => 1,
      'membership_id' => $membership['id'],
      'contribution_id' => $contrib['id'],
    ]);

    // Mock the GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    $this->mockGoCardlessApiForTestPaymentProcessor($api_prophecy->reveal());

    $redirect_flows_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\RedirectFlowsService');
    $api_prophecy->redirectFlows()->willReturn($redirect_flows_service->reveal());
    $redirect_flows_service->complete(Argument::any(), Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"redirect_url":"https://gocardless.com/somewhere","id":"RE1234","links":{"mandate":"MANDATEID"}}'));

    $subscription_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
    $api_prophecy->subscriptions()->willReturn($subscription_service->reveal());
    $subscription_service->create(Argument::any())
      ->willReturn(json_decode('{"start_date":"2016-10-08","id":"SUBSCRIPTION_ID"}'));
    // Params are usually assembled by the civicrm_buildForm hook.
    $params = [
      'test_mode' => TRUE,
      'redirect_flow_id' => 'RE1234',
      'session_token' => 'aabbccdd',
      'contactID' => $contact['id'],
      'description' => 'test contribution',
      'contributionID' => $contrib['id'],
      'contributionRecurID' => $recur['id'],
      'payment_processor_id' => $this->test_mode_payment_processor['id'],
      'membershipID' => $membership['id'],
      'entryURL' => 'http://example.com/somwhere',
    ];
    CRM_GoCardlessUtils::completeRedirectFlowCiviCore($params);

    $result = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);
    // Membership status should be still be Current
    $this->assertEquals($this->membership_status_map["Current"], $result['status_id']);
    // Dates should be unchanged
    foreach (['start_date', 'end_date', 'join_date'] as $date) {
      $this->assertEquals($membership[$date], $result[$date]);
    }
  }

  /**
   * Mostly the same as testTransferCheckoutCompletesWithoutInstallmentsNewMembership()
   * but tests with an existing Grace Membership - renewal is via GC but previous payments were not.
   */
  public function testTransferCheckoutCompletesWithoutInstallmentsExistingGraceMembership() {
    // We need to mimick what the contribution page does, which AFAICS does:
    // - Creates a Recurring Contribution
    $contact = civicrm_api3('Contact', 'create', array(
      'sequential' => 1,
      'contact_type' => "Individual",
      'first_name' => "Wilma",
      'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
      'sequential' => 1,
      'contact_id' => $contact['id'],
      'frequency_interval' => 1,
      'amount' => 1,
      'frequency_unit' => "month",
      'start_date' => "2016-10-01",
      'is_test' => 1,
      'contribution_status_id' => "Pending",
    ));
    $contrib = civicrm_api3('Contribution', 'create', array(
      'sequential' => 1,
    // Donation
      'financial_type_id' => 1,
      'total_amount' => 1,
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => "Pending",
      'is_test' => 1,
    ));
    // Mock existing membership
    $dt = new DateTimeImmutable();
    $membership = civicrm_api3('Membership', 'create', [
      'sequential' => 1,
      'membership_type_id' => 'MyMembershipType',
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'status_id' => "Grace",
    // Needed to override default status calculation
      'skipStatusCal' => 1,
      'start_date' => $dt->modify("-13 months")->format("Y-m-d"),
      'join_date' => $dt->modify("-25 months")->format("Y-m-d"),
    ]);
    // The dates returned by create and get are formatted differently!
    // So do a get here to make later comparison easier
    $membership = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);

    $membershipPayment = civicrm_api3('MembershipPayment', 'create', [
      'sequential' => 1,
      'membership_id' => $membership['id'],
      'contribution_id' => $contrib['id'],
    ]);

    // Mock the GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    $this->mockGoCardlessApiForTestPaymentProcessor($api_prophecy->reveal());

    $redirect_flows_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\RedirectFlowsService');
    $api_prophecy->redirectFlows()->willReturn($redirect_flows_service->reveal());
    $redirect_flows_service->complete(Argument::any(), Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"redirect_url":"https://gocardless.com/somewhere","id":"RE1234","links":{"mandate":"MANDATEID"}}'));

    $subscription_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
    $api_prophecy->subscriptions()->willReturn($subscription_service->reveal());
    $subscription_service->create(Argument::any())
      ->willReturn(json_decode('{"start_date":"2016-10-08","id":"SUBSCRIPTION_ID"}'));
    // Params are usually assembled by the civicrm_buildForm hook.
    $params = [
      'test_mode' => TRUE,
      'redirect_flow_id' => 'RE1234',
      'session_token' => 'aabbccdd',
      'contactID' => $contact['id'],
      'description' => 'test contribution',
      'contributionID' => $contrib['id'],
      'contributionRecurID' => $recur['id'],
      'membershipID' => $membership['id'],
      'payment_processor_id' => $this->test_mode_payment_processor['id'],
      'entryURL' => 'http://example.com/somwhere',
    ];
    CRM_GoCardlessUtils::completeRedirectFlowCiviCore($params);

    $result = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);
    // Membership status should be still be Current
    $this->assertEquals($this->membership_status_map["Grace"], $result['status_id']);
    // Dates should be unchanged
    foreach (['start_date', 'end_date', 'join_date'] as $date) {
      $this->assertEquals($membership[$date], $result[$date]);
    }
  }

  /**
   * Check a transfer checkout works when a number of contributions have been specified.
   *
   * Assumption: CiviContribute sets 'installments' on the recur record.
   */
  public function testTransferCheckoutCompletesWithInstallments() {
    // We need to mimick what the contribution page does.
    $contact = civicrm_api3('Contact', 'create', [
      'sequential' => 1,
      'contact_type' => "Individual",
      'first_name' => "Wilma",
      'last_name' => "Flintstone",
    ]);
    $recur = civicrm_api3('ContributionRecur', 'create', [
      'sequential' => 1,
      'contact_id' => $contact['id'],
      'frequency_interval' => 1,
      'amount' => 1,
      'frequency_unit' => "month",
      'start_date' => "2016-10-01",
      'is_test' => 1,
    // <--------------------- installment!
      'installments' => 7,
      'contribution_status_id' => "Pending",
    ]);
    $contrib = civicrm_api3('Contribution', 'create', [
      'sequential' => 1,
    // Donation
      'financial_type_id' => 1,
      'total_amount' => 1,
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => "Pending",
      'is_test' => 1,
    ]);

    // Mock the GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    $this->mockGoCardlessApiForTestPaymentProcessor($api_prophecy->reveal());

    $redirect_flows_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\RedirectFlowsService');
    $api_prophecy->redirectFlows()->willReturn($redirect_flows_service->reveal());
    $redirect_flows_service->complete(Argument::any(), Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"redirect_url":"https://gocardless.com/somewhere","id":"RE1234","links":{"mandate":"MANDATEID"}}'));

    $subscription_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
    $api_prophecy->subscriptions()->willReturn($subscription_service->reveal());
    $subscription_service->create([
      'params' => [
        'amount'        => 100,
        'currency'      => 'GBP',
        'interval'      => 1,
        'name'          => 'test contribution',
        'interval_unit' => 'monthly',
        'links'         => ['mandate' => 'MANDATEID'],
    // <-------------------------------- installments
        'count'         => 7,
      ],
    ])
      ->willReturn(json_decode('{"start_date":"2016-10-08","id":"SUBSCRIPTION_ID"}'))
      ->shouldBeCalled();
    // Params are usually assembled by the civicrm_buildForm hook.
    $params = [
      'test_mode' => TRUE,
      'redirect_flow_id' => 'RE1234',
      'session_token' => 'aabbccdd',
      'contactID' => $contact['id'],
      'description' => 'test contribution',
      'payment_processor_id' => $this->test_mode_payment_processor['id'],
      'contributionID' => $contrib['id'],
      'contributionRecurID' => $recur['id'],
      'entryURL' => 'http://example.com/somwhere',
    ];
    CRM_GoCardlessUtils::completeRedirectFlowCiviCore($params);

    // We're really just testing that the count parameter was passed to the API
    // which is tested by the shouldBeCalled() in the teardown method.
    // testTransferCheckoutCompletes() tested the updates to other stuff. The
    // following assertion is just to avoid phpunit flagging it as a test with
    // no assertions.
    $this->assertTrue(TRUE);
  }

  /**
   * Check missing signature throws InvalidArgumentException.
   *
   * @expectedException InvalidArgumentException
   * @expectedExceptionMessage Unsigned API request.
   */
  public function testWebhookMissingSignature() {
    $controller = new CRM_Core_Payment_GoCardlessIPN();
    $controller->parseWebhookRequest([], '');
  }

  /**
   * Check wrong signature throws InvalidArgumentException.
   *
   * @expectedException InvalidArgumentException
   * @expectedExceptionMessage Invalid signature in request.
   */
  public function testWebhookWrongSignature() {
    $controller = new CRM_Core_Payment_GoCardlessIPN();
    $controller->parseWebhookRequest(["Webhook-Signature" => 'foo'], 'bar');
  }

  /**
   * Check empty body throws InvalidArgumentException.
   *
   * @expectedException InvalidArgumentException
   * @expectedExceptionMessage Invalid or missing data in request.
   */
  public function testWebhookMissingBody() {
    $controller = new CRM_Core_Payment_GoCardlessIPN();
    $calculated_signature = hash_hmac("sha256", '', 'mock_webhook_key');
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], '');
  }

  /**
   * Check unparseable body throws InvalidArgumentException.
   *
   * @expectedException InvalidArgumentException
   * @expectedExceptionMessage Invalid or missing data in request.
   */
  public function testWebhookInvalidBody() {
    $controller = new CRM_Core_Payment_GoCardlessIPN();
    $body = 'This is not json.';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
  }

  /**
   * Check events extracted from webhook.
   *
   */
  public function testWebhookParse() {
    $controller = new CRM_Core_Payment_GoCardlessIPN();
    $body = '{"events":[
      {"id":"EV1","resource_type":"payments","action":"confirmed"},
      {"id":"EV2","resource_type":"payments","action":"failed"},
      {"id":"EV3","resource_type":"payments","action":"something we do not handle"},
      {"id":"EV4","resource_type":"subscriptions","action":"cancelled"},
      {"id":"EV5","resource_type":"subscriptions","action":"finished"},
      {"id":"EV6","resource_type":"subscriptions","action":"something we do not handle"},
      {"id":"EV7","resource_type":"unhandled_resource","action":"foo"}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);

    $this->assertInternalType('array', $controller->events);
    foreach (['EV1', 'EV2', 'EV4', 'EV5'] as $event_id) {
      $this->assertArrayHasKey($event_id, $controller->events);
    }
    $this->assertCount(4, $controller->events);
  }

  /**
   * A payment confirmation should update the initial Pending Contribution.
   *
   */
  public function testWebhookPaymentConfirmationFirst() {

    // when webhook called
    $dt = new DateTimeImmutable();
    $today = $dt->format("Y-m-d");
    // when DD setup
    $setup_date = $dt->modify("-5 days")->format("Y-m-d");
    // when GC charged
    $charge_date = $dt->modify("-2 days")->format("Y-m-d");

    $contact = civicrm_api3('Contact', 'create', array(
      'sequential' => 1,
      'contact_type' => "Individual",
      'first_name' => "Wilma",
      'last_name' => "Flintstone",
    ));

    $recur = civicrm_api3('ContributionRecur', 'create', array(
      'sequential' => 1,
      'contact_id' => $contact['id'],
    // Donation
      'financial_type_id' => 1,
      'frequency_interval' => 1,
      'amount' => 50,
      'frequency_unit' => "year",
      'start_date' => $setup_date,
      'is_test' => 1,
          //'contribution_status_id' => "In Progress",
      'contribution_status_id' => "Pending",
      'trxn_id' => 'SUBSCRIPTION_ID',
      'processor_id' => 'SUBSCRIPTION_ID',
    ));
    $contrib = civicrm_api3('Contribution', 'create', array(
      'sequential' => 1,
    // Donation
      'financial_type_id' => 1,
      'total_amount' => 1,
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => "Pending",
      'receive_date' => $setup_date,
      'is_test' => 1,
    ));
    $membership = civicrm_api3('Membership', 'create', [
      'sequential' => 1,
      'membership_type_id' => 'MyMembershipType',
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'status_id' => "Pending",
    // Needed to override default status calculation
      'skipStatusCal' => 1,
      'join_date' => $setup_date,
      'start_date' => $setup_date,
    ]);
    // The dates returned by create and get are formatted differently!
    // So do a get here to make later comparison easier
    $membership = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);
    $membershipPayment = civicrm_api3('MembershipPayment', 'create', [
      'sequential' => 1,
      'membership_id' => $membership['id'],
      'contribution_id' => $contrib['id'],
    ]);

    // Mock webhook input data.
    $controller = new CRM_Core_Payment_GoCardlessIPN();
    $body = '{"events":[
      {"id":"EV1","resource_type":"payments","action":"confirmed","links":{"payment":"PAYMENT_ID"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    $this->mockGoCardlessApiForTestPaymentProcessor($api_prophecy->reveal());
    // First the webhook will load the payment, so mock this.
    $payments_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\PaymentsService');
    $api_prophecy->payments()->willReturn($payments_service->reveal());
    $payments_service->get('PAYMENT_ID')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
        "id":"PAYMENT_ID",
          "status":"confirmed",
          "charge_date":"' . $charge_date . '",
          "amount":5000,
          "links":{"subscription":"SUBSCRIPTION_ID"}
        }'));

    // Now trigger webhook.
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
    $controller->processWebhookEvents(TRUE);

    // Now check the changes have been made.
    $result = civicrm_api3('Contribution', 'getsingle', ['id' => $contrib['id']]);
    $this->assertEquals($charge_date . ' 00:00:00', $result['receive_date']);
    $this->assertEquals('PAYMENT_ID', $result['trxn_id']);
    $this->assertEquals($this->contribution_status_map['Completed'], $result['contribution_status_id']);
    // Nb. this is an edge case:
    $this->assertEquals(50, $result['total_amount']);

    $result = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);
    // status should be updated to New
    $this->assertEquals($this->membership_status_map["New"], $result['status_id']);
    // join_date should be unchanged
    $this->assertEquals($membership['join_date'], $result['join_date']);
    // start_date set to setup date (i.e. probably? unchanged)
    // The original test expected this to be today's date, but while testing with 5.19.1
    // this was not the case. As those changes happen outside of this payment processor
    // I decided to go with what core now does...
    $this->assertEquals($setup_date, $result['start_date']);
    // end_date updated
    $this->assertEquals((new DateTimeImmutable($setup_date))->modify("+1 year")->modify("-1 day")->format("Y-m-d"), $result['end_date']);

    // Check the recur record has been updated.
    $result = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $result['contribution_recur_id']]);
    $contrib_recur_statuses = array_flip($this->contribution_recur_status_map);
    $this->assertEquals($contrib_recur_statuses[$result['contribution_status_id']], 'In Progress', 'Expected the contrib recur record to have status In Progress after first successful contribution received.');
  }

  /**
   * A payment confirmation should create a new contribution.
   *
   * Fixture:
   * - Crete contact
   * - Create a recuring payment with 1 completed payment in 1 Oct 2016
   *
   */
  public function testWebhookPaymentConfirmationSubsequent() {
    // when webhook called
    $dt = new DateTimeImmutable();
    $first_date_string = $dt->modify('-1 year')->format('Y-m-d');
    $second_charge_date = $dt->format('Y-m-d');

    $contact = civicrm_api3('Contact', 'create', array(
      'sequential' => 1,
      'contact_type' => "Individual",
      'first_name' => "Wilma",
      'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
      'sequential' => 1,
      'contact_id' => $contact['id'],
      'frequency_interval' => 1,
    // Donation
      'financial_type_id' => 1,
      'amount' => 1,
      'frequency_unit' => "month",
      'start_date' => $first_date_string,
      'is_test' => 1,
      'contribution_status_id' => "In Progress",
      'trxn_id' => 'SUBSCRIPTION_ID',
      'processor_id' => 'SUBSCRIPTION_ID',
      'payment_processor_id' => $this->test_mode_payment_processor['id'],
    ));

    // Mock that we have had one completed payment.
    $contrib = civicrm_api3('Contribution', 'create', array(
      'sequential' => 1,
      'total_amount' => 1,
    // Donation
      'financial_type_id' => 1,
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => "Completed",
      'receive_date' => $first_date_string,
      'is_test' => 1,
      'trxn_id' => 'PAYMENT_ID',
    ));

    // Mock existing membership
    $dt = new DateTimeImmutable();
    $membership = civicrm_api3('Membership', 'create', [
      'sequential' => 1,
      'membership_type_id' => 'MyMembershipType',
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'status_id' => "Current",
    // Needed to override default status calculation
      'skipStatusCal' => 1,
      'start_date' => $first_date_string,
      'join_date' => $first_date_string,
    ]);

    $membershipPayment = civicrm_api3('MembershipPayment', 'create', [
      'sequential' => 1,
      'membership_id' => $membership['id'],
      'contribution_id' => $contrib['id'],
    ]);

    // The dates returned by create and get are formatted differently!
    // So do a get here to make later comparison easier
    $membership = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);

    // Check end date is as expected.
    $this->assertEquals((new DateTimeImmutable($first_date_string))->modify("+1 year")->modify("-1 day")->format("Y-m-d"), $membership['end_date']);

    // Mock webhook input data.
    $controller = new CRM_Core_Payment_GoCardlessIPN();
    $body = '{"events":[
      {"id":"EV1","resource_type":"payments","action":"confirmed","links":{"payment":"PAYMENT_ID_2"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    $this->mockGoCardlessApiForTestPaymentProcessor($api_prophecy->reveal());
    // First the webhook will load the payment, so mock this.
    $payments_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\PaymentsService');
    $api_prophecy->payments()->willReturn($payments_service->reveal());
    $payments_service->get('PAYMENT_ID_2')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
        "id":"PAYMENT_ID_2",
        "status":"confirmed",
        "charge_date": "' . $second_charge_date . '",
        "amount":123,
        "links":{"subscription":"SUBSCRIPTION_ID"}
        }'));

    //
    // Mocks, done, now onto the code we are testing: trigger webhook.
    //
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
    $controller->processWebhookEvents(TRUE);

    //
    // Now check the changes have been made.
    //
    $result = civicrm_api3('Contribution', 'get', [
      'contribution_recur_id' => $recur['id'],
      'is_test' => 1,
    ]);
    // Should be 2 records now.
    $this->assertEquals(2, $result['count']);
    // Ensure we have the first one.
    $this->assertArrayHasKey($contrib['id'], $result['values']);
    // Now we can get rid of it.
    unset($result['values'][$contrib['id']]);
    // And the remaining one should be our new one.
    $contrib = reset($result['values']);

    $this->assertEquals("$second_charge_date 00:00:00", $contrib['receive_date']);
    $this->assertEquals(1.23, $contrib['total_amount']);
    $this->assertEquals('PAYMENT_ID_2', $contrib['trxn_id']);
    $this->assertEquals($this->contribution_status_map['Completed'], $contrib['contribution_status_id']);

    // Check membership
    $result = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);
    $this->assertEquals($this->membership_status_map["Current"], $result['status_id']);
    // join_date and start_date are unchanged
    $this->assertEquals($membership['join_date'], $result['join_date']);
    $this->assertEquals($membership['start_date'], $result['start_date']);
    // end_date is 12 months later
    $end_dt = new DateTimeImmutable($membership['end_date']);
    $this->assertEquals($end_dt->modify("+12 months")->format("Y-m-d"), $result['end_date']);

  }

  /**
   * A payment confirmation should not change a recur status from Cancelled to In Progress.
   * See Issue 54
   *
   */
  public function testWebhookPaymentConfirmationDoesNotMarkCancelledAsInProgress() {
    $dt = new DateTimeImmutable();
    $first_date_string = $dt->modify('-1 year')->format('Y-m-d');
    $second_charge_date = $dt->format('Y-m-d');

    $contact = civicrm_api3('Contact', 'create', array(
      'sequential' => 1,
      'contact_type' => "Individual",
      'first_name' => "Wilma",
      'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
      'sequential' => 1,
      'contact_id' => $contact['id'],
      'frequency_interval' => 1,
    // Donation
      'financial_type_id' => 1,
      'amount' => 1,
      'frequency_unit' => "month",
      'start_date' => $first_date_string,
      'is_test' => 1,
      'contribution_status_id' => "Cancelled",
      'trxn_id' => 'SUBSCRIPTION_ID',
      'processor_id' => 'SUBSCRIPTION_ID',
      'payment_processor_id' => $this->test_mode_payment_processor['id'],
    ));

    // Mock that we have had one completed payment.
    $contrib = civicrm_api3('Contribution', 'create', array(
      'sequential' => 1,
      'total_amount' => 1,
    // Donation
      'financial_type_id' => 1,
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => "Completed",
      'receive_date' => $first_date_string,
      'is_test' => 1,
      'trxn_id' => 'PAYMENT_ID',
    ));

    // Mock existing membership
    $dt = new DateTimeImmutable();
    $membership = civicrm_api3('Membership', 'create', [
      'sequential' => 1,
      'membership_type_id' => 'MyMembershipType',
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'status_id' => "Current",
    // Needed to override default status calculation
      'skipStatusCal' => 1,
      'start_date' => $dt->modify("-11 months")->format("Y-m-d"),
      'join_date' => $dt->modify("-23 months")->format("Y-m-d"),
    ]);
    // The dates returned by create and get are formatted differently!
    // So do a get here to make later comparison easier
    $membership = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);
    $membershipPayment = civicrm_api3('MembershipPayment', 'create', [
      'sequential' => 1,
      'membership_id' => $membership['id'],
      'contribution_id' => $contrib['id'],
    ]);

    // Mock webhook input data.
    $controller = new CRM_Core_Payment_GoCardlessIPN();
    $body = '{"events":[
      {"id":"EV1","resource_type":"payments","action":"confirmed","links":{"payment":"PAYMENT_ID_2"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    $this->mockGoCardlessApiForTestPaymentProcessor($api_prophecy->reveal());
    // First the webhook will load the payment, so mock this.
    $payments_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\PaymentsService');
    $api_prophecy->payments()->willReturn($payments_service->reveal());
    $payments_service->get('PAYMENT_ID_2')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
        "id":"PAYMENT_ID_2",
        "status":"confirmed",
        "charge_date": "' . $second_charge_date . '",
        "amount":123,
        "links":{"subscription":"SUBSCRIPTION_ID"}
        }'));

    //
    // Mocks, done, now onto the code we are testing: trigger webhook.
    //
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
    $controller->processWebhookEvents(TRUE);

    //
    // Now check the changes have been made.
    //
    $result = civicrm_api3('Contribution', 'get', [
      'contribution_recur_id' => $recur['id'],
      'is_test' => 1,
    ]);
    // Should be 2 records now.
    $this->assertEquals(2, $result['count']);
    // Ensure we have the first one.
    $this->assertArrayHasKey($contrib['id'], $result['values']);
    // Now we can get rid of it.
    unset($result['values'][$contrib['id']]);
    // And the remaining one should be our new one.
    $contrib = reset($result['values']);

    // Check the recur status is still cancelled.
    $result = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $contrib['contribution_recur_id']]);
    $contrib_recur_statuses = array_flip($this->contribution_recur_status_map);
    $this->assertEquals($contrib_recur_statuses[$result['contribution_status_id']], 'Cancelled',
      'Expected the contrib recur record to STILL have status Cancelled after a successful contribution received.');
  }

  /**
   * A payment failed should update the initial Pending Contribution.
   *
   * Also, if a successful payment comes in at a later date, that should work normally.
   *
   */
  public function testWebhookPaymentFailedFirst() {

    $contact = civicrm_api3('Contact', 'create', array(
      'sequential' => 1,
      'contact_type' => "Individual",
      'first_name' => "Wilma",
      'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
      'sequential' => 1,
      'contact_id' => $contact['id'],
    // Donation
      'financial_type_id' => 1,
      'frequency_interval' => 1,
      'amount' => 1,
      'frequency_unit' => "month",
      'start_date' => "2016-10-01",
      'is_test' => 1,
      'contribution_status_id' => "In Progress",
      'trxn_id' => 'SUBSCRIPTION_ID',
      'processor_id' => 'SUBSCRIPTION_ID',
      'payment_processor_id' => $this->test_mode_payment_processor['id'],
    ));
    $contrib = civicrm_api3('Contribution', 'create', array(
      'sequential' => 1,
    // Donation
      'financial_type_id' => 1,
      'total_amount' => 1,
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => "Pending",
      'receive_date' => '2016-10-01',
      'is_test' => 1,
    ));

    // Mock webhook input data.
    $body = '{"events":[
      {"id":"EV1","resource_type":"payments","action":"failed","links":{"payment":"PAYMENT_ID"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock API
    $this->mockPaymentGet('{
        "id":"PAYMENT_ID",
          "status":"failed",
          "charge_date":"2016-10-02",
          "amount":123,
          "links":{"subscription":"SUBSCRIPTION_ID"}
        }'
    );

    // -------------------------------------------------------
    // What we're testing: Trigger webhook.
    // -------------------------------------------------------
    $controller = new CRM_Core_Payment_GoCardlessIPN();
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
    $controller->processWebhookEvents(TRUE);

    // -------------------------------------------------------
    // Test: the initial contrib should show as failed.
    $result = civicrm_api3('Contribution', 'getsingle', ['id' => $contrib['id']]);
    $this->assertEquals('2016-10-02 00:00:00', $result['receive_date']);
    $this->assertEquals(1.23, $result['total_amount']);
    $this->assertEquals('PAYMENT_ID', $result['trxn_id']);
    $this->assertEquals($this->contribution_status_map['Failed'], $result['contribution_status_id']);

    // Test: the contrib recur should be 'overdue'
    $recur = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $recur['id']]);
    $contrib_recur_statuses = array_flip($this->contribution_recur_status_map);
    $this->assertEquals('Overdue', $contrib_recur_statuses[$recur['contribution_status_id']]);

    // -------------------------------------------------------
    // Mock a second, failed contribution.
    // -------------------------------------------------------
    $body = '{"events":[
      {"id":"EV2","resource_type":"payments","action":"failed","links":{"payment":"PAYMENT_ID_2"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock API
    $this->mockPaymentGet('{
        "id":"PAYMENT_ID_2",
          "status":"failed",
          "charge_date":"2016-11-02",
          "amount":123,
          "links":{"subscription":"SUBSCRIPTION_ID"}
        }'
    );

    // -------------------------------------------------------
    // What we're testing: Trigger webhook.
    // -------------------------------------------------------
    $controller = new CRM_Core_Payment_GoCardlessIPN();
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
    $controller->processWebhookEvents(TRUE);

    // -------------------------------------------------------
    // Test: Original (now failed) payment is unaffected
    $result = civicrm_api3('Contribution', 'getsingle', ['id' => $contrib['id']]);
    $this->assertEquals('2016-10-02 00:00:00', $result['receive_date']);
    $this->assertEquals(1.23, $result['total_amount']);
    $this->assertEquals('PAYMENT_ID', $result['trxn_id']);
    $this->assertEquals($this->contribution_status_map['Failed'], $result['contribution_status_id']);

    // -------------------------------------------------------
    // Test: Should be a new, failed payment
    $result = $this->getLatestContribution($recur['id']);
    $this->assertEquals('2016-11-02 00:00:00', $result['receive_date']);
    $this->assertEquals(1.23, $result['total_amount']);
    $this->assertEquals('PAYMENT_ID_2', $result['trxn_id']);
    $this->assertEquals($this->contribution_status_map['Failed'], $result['contribution_status_id']);


    // -------------------------------------------------------
    // Mock a third, and our first successful ('confirmed'), contribution.
    // @see https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/82
    // -------------------------------------------------------
    $body = '{"events":[
      {"id":"EV3","resource_type":"payments","action":"confirmed","links":{"payment":"PAYMENT_ID_3"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock API
    $this->mockPaymentGet('{
        "id":"PAYMENT_ID_3",
        "status":"confirmed",
        "charge_date":"2016-12-02",
        "amount":123,
        "links":{"subscription":"SUBSCRIPTION_ID"}
      }'
    );

    // -------------------------------------------------------
    // What we're testing: Trigger webhook.
    // -------------------------------------------------------
    $controller = new CRM_Core_Payment_GoCardlessIPN();
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
    $controller->processWebhookEvents(TRUE);

    // -------------------------------------------------------
    // Test: Should be a new, successful payment
    $result = $this->getLatestContribution($recur['id']);
    $this->assertEquals('2016-12-02 00:00:00', $result['receive_date']);
    $this->assertEquals(1.23, $result['total_amount']);
    $this->assertEquals('PAYMENT_ID_3', $result['trxn_id']);
    $this->assertEquals($this->contribution_status_map['Completed'], $result['contribution_status_id']);

    // -------------------------------------------------------
    // Test: the contrib recur should be In Progress again.
    $recur = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $recur['id']]);
    $contrib_recur_statuses = array_flip($this->contribution_recur_status_map);
    $this->assertEquals('In Progress', $contrib_recur_statuses[$recur['contribution_status_id']]);

  }

  /**
   * A payment confirmation should create a new contribution.
   *
   */
  public function testWebhookPaymentFailedSubsequent() {

    $contact = civicrm_api3('Contact', 'create', array(
      'sequential' => 1,
      'contact_type' => "Individual",
      'first_name' => "Wilma",
      'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
      'sequential' => 1,
      'contact_id' => $contact['id'],
      'frequency_interval' => 1,
    // Donation
      'financial_type_id' => 1,
      'amount' => 1,
      'frequency_unit' => "month",
      'start_date' => "2016-10-01",
      'is_test' => 1,
      'contribution_status_id' => "In Progress",
      'trxn_id' => 'SUBSCRIPTION_ID',
      'processor_id' => 'SUBSCRIPTION_ID',
      'payment_processor_id' => $this->test_mode_payment_processor['id'],
    ));

    // Mock that we have had one completed payment.
    $contrib = civicrm_api3('Contribution', 'create', array(
      'sequential' => 1,
      'total_amount' => 1,
    // Donation
      'financial_type_id' => 1,
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => "Completed",
      'receive_date' => '2016-10-01',
      'is_test' => 1,
      'trxn_id' => 'PAYMENT_ID',
    ));

    // Mock webhook input data.
    $controller = new CRM_Core_Payment_GoCardlessIPN();
    $body = '{"events":[
      {"id":"EV1","resource_type":"payments","action":"failed","links":{"payment":"PAYMENT_ID_2"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    $this->mockGoCardlessApiForTestPaymentProcessor($api_prophecy->reveal());
    // First the webhook will load the payment, so mock this.
    $payments_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\PaymentsService');
    $api_prophecy->payments()->willReturn($payments_service->reveal());
    $payments_service->get('PAYMENT_ID_2')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
        "id":"PAYMENT_ID_2",
        "status":"failed",
        "charge_date":"2016-10-02",
        "amount":123,
        "links":{"subscription":"SUBSCRIPTION_ID"}
        }'));

    // Now trigger webhook.
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
    $controller->processWebhookEvents(TRUE);

    // Now check the changes have been made.
    $result = civicrm_api3('Contribution', 'get', [
      'contribution_recur_id' => $recur['id'],
      'is_test' => 1,
    ]);
    // Should be 2 records now.
    $this->assertEquals(2, $result['count']);
    // Ensure we have the first one.
    $this->assertArrayHasKey($contrib['id'], $result['values']);
    // Now we can get rid of it.
    unset($result['values'][$contrib['id']]);
    // And the remaining one should be our new one.
    $contrib = reset($result['values']);

    $this->assertEquals('2016-10-02 00:00:00', $contrib['receive_date']);
    $this->assertEquals(1.23, $contrib['total_amount']);
    $this->assertEquals('PAYMENT_ID_2', $contrib['trxn_id']);
    $this->assertEquals($this->contribution_status_map['Failed'], $contrib['contribution_status_id']);

  }

  /**
   * Late Payments.
   * See Issue 55
   *
   */
  public function testWebhookPaymentFailedLate() {

    $contact = civicrm_api3('Contact', 'create', array(
      'sequential' => 1,
      'contact_type' => "Individual",
      'first_name' => "Wilma",
      'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
      'sequential' => 1,
      'contact_id' => $contact['id'],
      'frequency_interval' => 1,
    // Donation
      'financial_type_id' => 1,
      'amount' => 1,
      'frequency_unit' => "month",
      'start_date' => "2016-10-01",
      'is_test' => 1,
      'contribution_status_id' => "Cancelled",
      'trxn_id' => 'SUBSCRIPTION_ID',
      'processor_id' => 'SUBSCRIPTION_ID',
      'payment_processor_id' => $this->test_mode_payment_processor['id'],
    ));

    // Mock that we have had one completed payment.
    $contrib = civicrm_api3('Contribution', 'create', array(
      'sequential' => 1,
      'total_amount' => 1,
    // Donation
      'financial_type_id' => 1,
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => "Completed",
      'receive_date' => '2016-10-01',
      'is_test' => 1,
      'trxn_id' => 'PAYMENT_ID',
    ));

    // Mock webhook input data.
    $controller = new CRM_Core_Payment_GoCardlessIPN();
    $body = '{"events":[
      {"id":"EV1","resource_type":"payments","action":"failed","links":{"payment":"PAYMENT_ID"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    $this->mockGoCardlessApiForTestPaymentProcessor($api_prophecy->reveal());
    // First the webhook will load the payment, so mock this.
    $payments_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\PaymentsService');
    $api_prophecy->payments()->willReturn($payments_service->reveal());
    $payments_service->get('PAYMENT_ID')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
        "id":"PAYMENT_ID",
        "status":"failed",
        "charge_date":"2016-10-02",
        "amount":123,
        "links":{"subscription":"SUBSCRIPTION_ID"}
        }'));

    // Now trigger webhook.
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
    $controller->processWebhookEvents(TRUE);

    // Now check the changes have been made.
    $result = civicrm_api3('Contribution', 'getsingle', ['id' => $contrib['id']]);
    $this->assertEquals($this->contribution_status_map['Refunded'], $result['contribution_status_id']);
    $this->assertEquals('PAYMENT_ID', $result['trxn_id']);
  }

  /**
   * A payment confirmation webhook that is out of date.
   *
   * @expectedException CRM_GoCardless_WebhookEventIgnoredException
   * @expectedExceptionMessage Webhook out of date
   */
  public function testWebhookOutOfDate() {

    $controller = $this->getWebhookControllerForTestProcessor();

    // Mock GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    $this->mockGoCardlessApiForTestPaymentProcessor($api_prophecy->reveal());
    // First the webhook will load the payment, so mock this.
    $payments_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\PaymentsService');
    $api_prophecy->payments()->willReturn($payments_service->reveal());
    $payments_service->get('PAYMENT_ID')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
        "id":"PAYMENT_ID",
          "status":"cancelled",
          "charge_date":"2016-10-02",
          "amount":123,
          "links":{"subscription":"SUBSCRIPTION_ID"}
        }'));

    // Now trigger webhook.
    $event = json_decode(json_encode(['links' => ['payment' => 'PAYMENT_ID']]));
    // Calling with different status to that which will be fetched from API.
    $controller->getAndCheckGoCardlessPayment($event, ['confirmed']);
  }

  /**
   * A subscription cancelled webhook that is out of date.
   *
   * @expectedException CRM_GoCardless_WebhookEventIgnoredException
   * @expectedExceptionMessage Webhook out of date
   */
  public function testWebhookOutOfDateSubscription() {
    // Mock GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    $this->mockGoCardlessApiForTestPaymentProcessor($api_prophecy->reveal());
    // First the webhook will load the subscription, so mock this.
    $subscription_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
    $api_prophecy->subscriptions()->willReturn($subscription_service->reveal());
    $subscription_service->get('SUBSCRIPTION_ID')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
        "id":"SUBSCRIPTION_ID",
        "status":"cancelled"
        }'));

    $event = json_decode('{"links":{"subscription":"SUBSCRIPTION_ID"}}');
    $controller = $this->getWebhookControllerForTestProcessor();
    // Calling with different status to that which will be fetched from API.
    $controller->getAndCheckSubscription($event, 'complete');
  }

  /**
   * A payment confirmation webhook event that does not have a subscription
   * should be ignored.
   *
   * @expectedException CRM_GoCardless_WebhookEventIgnoredException
   * @expectedExceptionMessage Ignored payment that does not belong to a subscription.
   */
  public function testWebhookPaymentWithoutSubscriptionIgnored() {

    $controller = $this->getWebhookControllerForTestProcessor();

    // Mock GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    $this->mockGoCardlessApiForTestPaymentProcessor($api_prophecy->reveal());
    // First the webhook will load the payment, so mock this.
    $payments_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\PaymentsService');
    $api_prophecy->payments()->willReturn($payments_service->reveal());
    $payments_service->get('PAYMENT_ID')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
        "id":"PAYMENT_ID",
          "status":"confirmed",
          "charge_date":"2016-10-02",
          "amount":123,
          "links":{}
        }'));

    // Now trigger webhook.
    $event = json_decode('{"links":{"payment":"PAYMENT_ID"}}');
    // Calling with different status to that which will be fetched from API.
    $controller->getAndCheckGoCardlessPayment($event, ['confirmed']);
  }

  /**
   * A subscription cancelled should update the recurring contribution record
   * and a Pending Contribution.
   *
   */
  public function testWebhookSubscriptionCancelled() {

    $contact = civicrm_api3('Contact', 'create', array(
      'sequential' => 1,
      'contact_type' => "Individual",
      'first_name' => "Wilma",
      'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
      'sequential' => 1,
      'contact_id' => $contact['id'],
      'frequency_interval' => 1,
    // Donation
      'financial_type_id' => 1,
      'amount' => 1,
      'frequency_unit' => "month",
      'start_date' => "2016-10-01",
      'is_test' => 1,
      'contribution_status_id' => "In Progress",
      'trxn_id' => 'SUBSCRIPTION_ID',
      'processor_id' => 'SUBSCRIPTION_ID',
    ));

    // Mark this contrib as Incomplete - this is the case that the thing's just
    // been set up by a Contribution Page.
    $contrib = civicrm_api3('Contribution', 'create', array(
      'sequential' => 1,
      'total_amount' => 1,
    // Donation
      'financial_type_id' => 1,
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => "Pending",
      'receive_date' => '2016-10-01',
      'is_test' => 1,
    ));

    // Mock webhook input data.
    $controller = new CRM_Core_Payment_GoCardlessIPN();
    $body = '{"events":[
      {"id":"EV1","resource_type":"subscriptions","action":"cancelled","links":{"subscription":"SUBSCRIPTION_ID"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    $this->mockGoCardlessApiForTestPaymentProcessor($api_prophecy->reveal());
    // First the webhook will load the subscription, so mock this.
    $subscription_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
    $api_prophecy->subscriptions()->willReturn($subscription_service->reveal());
    $subscription_service->get('SUBSCRIPTION_ID')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
          "id":"SUBSCRIPTION_ID",
          "status":"cancelled",
          "end_date":"2016-10-02"
        }'));

    // Now trigger webhook.
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
    $controller->processWebhookEvents(TRUE);

    // Now check the changes have been made to the original contribution.
    $contrib = civicrm_api3('Contribution', 'getsingle', [
      'contribution_recur_id' => $recur['id'],
      'is_test' => 1,
    ]);
    $this->assertEquals($this->contribution_status_map['Cancelled'], $contrib['contribution_status_id']);

    // Now check the changes have been made to the recurring contribution.
    $contrib = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $recur['id']]);
    $this->assertEquals('2016-10-02 00:00:00', $contrib['end_date']);
    $this->assertEquals('SUBSCRIPTION_ID', $contrib['trxn_id']);
    $this->assertEquals($this->contribution_status_map['Cancelled'], $contrib['contribution_status_id']);

  }

  /**
   * A subscription cancelled should update the recurring contribution record
   * and a Pending Contribution.
   *
   */
  public function testWebhookSubscriptionFinished() {

    $contact = civicrm_api3('Contact', 'create', array(
      'sequential' => 1,
      'contact_type' => "Individual",
      'first_name' => "Wilma",
      'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
      'sequential' => 1,
      'contact_id' => $contact['id'],
      'frequency_interval' => 1,
    // Donation
      'financial_type_id' => 1,
      'amount' => 1,
      'frequency_unit' => "month",
      'start_date' => "2016-10-01",
      'is_test' => 1,
      'contribution_status_id' => "In Progress",
      'trxn_id' => 'SUBSCRIPTION_ID',
      'processor_id' => 'SUBSCRIPTION_ID',
    ));

    // Mark this contrib as Incomplete - this is the case that the thing's just
    // been set up by a Contribution Page.
    $contrib = civicrm_api3('Contribution', 'create', array(
      'sequential' => 1,
      'total_amount' => 1,
    // Donation
      'financial_type_id' => 1,
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => "Pending",
      'receive_date' => '2016-10-01',
      'is_test' => 1,
    ));

    // Mock webhook input data.
    $controller = new CRM_Core_Payment_GoCardlessIPN();
    $body = '{"events":[
      {"id":"EV1","resource_type":"subscriptions","action":"finished","links":{"subscription":"SUBSCRIPTION_ID"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    $this->mockGoCardlessApiForTestPaymentProcessor($api_prophecy->reveal());
    // First the webhook will load the subscription, so mock this.
    $subscription_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
    $api_prophecy->subscriptions()->willReturn($subscription_service->reveal());
    $subscription_service->get('SUBSCRIPTION_ID')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
          "id":"SUBSCRIPTION_ID",
          "status":"finished",
          "end_date":"2016-10-02"
        }'));

    // Now trigger webhook.
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
    $controller->processWebhookEvents(TRUE);

    // Now check the changes have been made to the original contribution.
    // This should be Cancelled because the thing finished before it could be taken.
    $contrib = civicrm_api3('Contribution', 'getsingle', [
      'contribution_recur_id' => $recur['id'],
      'is_test' => 1,
    ]);
    $this->assertEquals($this->contribution_status_map['Cancelled'], $contrib['contribution_status_id']);

    // Now check the changes have been made to the recurring contribution.
    $contrib = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $recur['id']]);
    $this->assertEquals('2016-10-02 00:00:00', $contrib['end_date']);
    $this->assertEquals('SUBSCRIPTION_ID', $contrib['trxn_id']);
    $this->assertEquals($this->contribution_status_map['Completed'], $contrib['contribution_status_id']);

  }

  /**
   * According to issue 59, CiviCRM sets up 'weekly' memberships by passing
   * in a 7 day interval.
   *
   * This creates a contact with a contribution and a ContributionRecur in the
   * same way that CiviCRM's core Contribution Pages form does, then, having
   * mocked the GC API it calls
   * CRM_GoCardlessUtils::completeRedirectFlowCiviCore()
   * and checks that the result is updated contribution and ContributionRecur records.
   *
   */
  public function testIssue59() {
    // We need to mimick what the contribution page does, which AFAICS does:
    // - Creates a Recurring Contribution
    $contact = civicrm_api3('Contact', 'create', array(
      'sequential' => 1,
      'contact_type' => "Individual",
      'first_name' => "Wilma",
      'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
      'sequential' => 1,
      'contact_id' => $contact['id'],
    // Issue59
      'frequency_unit' => "day",
    // Issue59
      'frequency_interval' => 7,
      'amount' => 1,
      'start_date' => "2016-10-01",
      'is_test' => 1,
      'contribution_status_id' => "Pending",
    ));
    $contrib = civicrm_api3('Contribution', 'create', array(
      'sequential' => 1,
    // Donation
      'financial_type_id' => 1,
      'total_amount' => 1,
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => "Pending",
      'is_test' => 1,
    ));
    $membership = civicrm_api3('Membership', 'create', [
      'sequential' => 1,
      'membership_type_id' => 'MyMembershipType',
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'status_id' => "Pending",
    // Needed to override default status calculation
      'skipStatusCal' => 1,
    ]);
    // The dates returned by create and get are formatted differently!
    // So do a get here to make later comparison easier
    $membership = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);

    $membershipPayment = civicrm_api3('MembershipPayment', 'create', [
      'sequential' => 1,
      'membership_id' => $membership['id'],
      'contribution_id' => $contrib['id'],
    ]);

    // Mock the GC API.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    $this->mockGoCardlessApiForTestPaymentProcessor($api_prophecy->reveal());

    $redirect_flows_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\RedirectFlowsService');
    $api_prophecy->redirectFlows()->willReturn($redirect_flows_service->reveal());
    $redirect_flows_service->complete(Argument::any(), Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"redirect_url":"https://gocardless.com/somewhere","id":"RE1234","links":{"mandate":"MANDATEID"}}'));

    $subscription_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
    $api_prophecy->subscriptions()->willReturn($subscription_service->reveal());
    $subscription_service->create(Argument::any())
      ->willReturn(json_decode('{"start_date":"2016-10-08","id":"SUBSCRIPTION_ID"}'));
    // Params are usually assembled by the civicrm_buildForm hook.
    $params = [
      'test_mode' => TRUE,
      'redirect_flow_id' => 'RE1234',
      'session_token' => 'aabbccdd',
      'contactID' => $contact['id'],
      'description' => 'test contribution',
      'contributionID' => $contrib['id'],
      'contributionRecurID' => $recur['id'],
      'membershipID' => $membership['id'],
      'payment_processor_id' => $this->test_mode_payment_processor['id'],
      'entryURL' => 'http://example.com/somwhere',
    ];
    CRM_GoCardlessUtils::completeRedirectFlowCiviCore($params);

    // Now test the contributions were updated.
    $result = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $recur['id']]);

    // This would fail due to issue59.
    $this->assertEquals(5, $result['contribution_status_id']);

    $this->assertEquals('SUBSCRIPTION_ID', $result['trxn_id']);
    $this->assertEquals('2016-10-08 00:00:00', $result['start_date']);
    $result = civicrm_api3('Contribution', 'getsingle', ['id' => $contrib['id']]);
    $this->assertEquals('2016-10-08 00:00:00', $result['receive_date']);
    $this->assertEquals(2, $result['contribution_status_id']);
    $result = civicrm_api3('Membership', 'getsingle', ['id' => $membership['id']]);
    // status should be still be Pending
    $this->assertEquals($this->membership_status_map["Pending"], $result['status_id']);
    // Dates should be unchanged
    foreach (['start_date', 'end_date', 'join_date'] as $date) {
      $this->assertEquals($membership[$date], $result[$date]);
    }
  }

  public function testUpgrade0002() {
    $payment_instrument = civicrm_api3('OptionValue', 'getsingle', [ 'option_group_id' => "payment_instrument", 'name' => "direct_debit_gc" ])['value'];
    $processor_type = civicrm_api3('PaymentProcessorType', 'getsingle', [ 'name' => 'GoCardless', 'options' => ['limit' => 0] ])['id'];

    // After an install, our processor should be correctly set up.
    $proc = civicrm_api3('PaymentProcessor', 'getsingle', ['id' => $this->test_mode_payment_processor['id']]);
    $this->assertEquals($payment_instrument, $proc['payment_instrument_id']);
    $this->assertEquals(CRM_Core_Payment::PAYMENT_TYPE_DIRECT_DEBIT, $proc['payment_type']);

    // Now bodge this backwards.
    CRM_Core_DAO::executeQuery("UPDATE civicrm_payment_processor SET payment_type=1, payment_instrument_id=1 WHERE id = %1", [
      1 => [$this->test_mode_payment_processor['id'], 'Integer']
    ]);

    // Now run the upgrade
    $up = new CRM_GoCardless_Upgrader(E::LONG_NAME, E::path());
    $up->upgrade_0002();

    // And re-test
    $proc = civicrm_api3('PaymentProcessor', 'getsingle', ['id' => $this->test_mode_payment_processor['id']]);
    $this->assertEquals($payment_instrument, $proc['payment_instrument_id']);
    $this->assertEquals(CRM_Core_Payment::PAYMENT_TYPE_DIRECT_DEBIT, $proc['payment_type']);

  }
  /**
   * check sending receipts.
   *
   * Variables: contrib recur, contrib API, sendReceiptsForCustomPayments
   *
   * @dataProvider dataForTestSendReceipts
   */
  public function testSendReceipts($policy, $recur_is_email_receipt, $expected) {
    $mut = new CiviMailUtils($this, TRUE);

    $settings = CRM_GoCardlessUtils::getSettings();
    $settings['sendReceiptsForCustomPayments'] = $policy;
    Civi::settings()->set('gocardless', json_encode($settings));

    $contact = civicrm_api3('Contact', 'create', array(
      'sequential' => 1,
      'contact_type' => "Individual",
      'first_name' => "Wilma",
      'last_name' => "Flintstone",
      'email' => 'wilma@example.org'
    ));

    // when DD setup
    $setup_date = '2016-10-02';
    $recur = civicrm_api3('ContributionRecur', 'create', array(
      'sequential' => 1,
      'is_email_receipt' => $recur_is_email_receipt,
      'contact_id' => $contact['id'],
      'financial_type_id' => 1,
      'frequency_interval' => 1,
      'amount' => 50,
      'frequency_unit' => "year",
      'start_date' => $setup_date,
      'is_test' => 1,
      'contribution_status_id' => "In Progress",
      'trxn_id' => 'SUBSCRIPTION_ID',
      'processor_id' => 'SUBSCRIPTION_ID',
    ));

    // Create pending contrib.
    $contribution = civicrm_api3('Contribution', 'create', array(
      'sequential' => 1,
      'financial_type_id' => 1,
      'total_amount' => 1,
      'contact_id' => $contact['id'],
      'contribution_recur_id' => $recur['id'],
      'contribution_status_id' => "Pending",
      'receive_date' => $setup_date,
      'is_test' => 1,
    ));


    // Mock webhook input data.
    $controller = new CRM_Core_Payment_GoCardlessIPN();
    $body = '{"events":[
      {"id":"EV1","resource_type":"payments","action":"confirmed","links":{"payment":"PAYMENT_ID"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock API
    $this->mockPaymentGet('{
        "id":"PAYMENT_ID",
        "status":"confirmed",
        "charge_date": "2016-10-02",
        "amount":5000,
        "links":{"subscription":"SUBSCRIPTION_ID"}
      }'
    );

    // Trigger webhook
    $controller = new CRM_Core_Payment_GoCardlessIPN();
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
    $controller->processWebhookEvents(TRUE);

    $receipt_date = civicrm_api3('Contribution', 'getvalue', ['id' => $contribution['id'], 'return' => 'receipt_date']);

    if ($expected) {
      // Check that a receipt WAS sent.
      $this->assertNotEmpty($receipt_date);
      $mut->checkMailLog(['Contribution Information']);
    }
    else {
      // Check it was NOT sent.
      $this->assertEmpty($receipt_date);
      $mut->checkMailLog([], ['Contribution Information']);
    }
    $mut->stop();
  }
  /**
   * Data provider for testSendReceipts
   */
  public function dataForTestSendReceipts() {
    return [
      [ 'always', 0, 1 ],
      [ 'always', 1, 1 ],
      [ 'never', 0, 0 ],
      [ 'never', 1, 0 ],
      [ 'defer', 0, 0 ],
      [ 'defer', 1, 1 ],
    ];
  }
  /**
   * Return a fake GoCardless IPN processor.
   *
   * Helper function for other tests.
   */
  protected function getWebhookControllerForTestProcessor() {
    $pp_config = $this->test_mode_payment_processor;
    $pp = Civi\Payment\System::singleton()->getByProcessor($pp_config);
    $controller = new CRM_Core_Payment_GoCardlessIPN($pp);
    return $controller;
  }

  /**
   * Helper
   */
  protected function mockGoCardlessApiForTestPaymentProcessor($mock) {
    $obj = new CRM_Core_Payment_GoCardless('test', $this->test_mode_payment_processor);
    $obj->setGoCardlessApi($mock);
  }

  /**
   * DRY code.
   *
   * @param string $paymentJSON
   *
   * @return prophecy
   */
  protected function mockPaymentGet($paymentJSON) {
    $payment = json_decode($paymentJSON);
    if (!$payment) {
      throw new \InvalidArgumentException("test code error: \$paymentJSON must be valid json but wasn't");
    }

    // First the webhook will load the payment, so mock this.
    $api_prophecy = $this->prophet->prophesize('\\GoCardlessPro\\Client');
    $this->mockGoCardlessApiForTestPaymentProcessor($api_prophecy->reveal());
    $payments_service = $this->prophet->prophesize('\\GoCardlessPro\\Services\\PaymentsService');
    $api_prophecy->payments()->willReturn($payments_service->reveal());
    $payments_service->get($payment->id)
      ->shouldBeCalled()
      ->willReturn($payment);

    return $payments_service;
  }
  /**
   * Fetch the last contribution (by ID) for the given recurID.
   *
   * @param int $recurID
   *
   * @return array
   */
  protected function getLatestContribution($recurID) {
    $r = civicrm_api3('Contribution', 'get', [
      'contribution_recur_id' => $recurID,
      'sequential' => 1,
      'is_test' => 1,
      'options' => ['limit' => 0, 'sort' => "id ASC"],
    ])['values'] ?? [];
    if ($r) {
      return end($r);
    }
  }
}
