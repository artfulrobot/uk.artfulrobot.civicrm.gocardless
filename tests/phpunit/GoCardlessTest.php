<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;
use \Prophecy\Argument;

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
class GoCardlessTest extends \PHPUnit_Framework_TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();

    // Set up a Payment Processor that uses GC.

    $result = civicrm_api3('PaymentProcessor', 'create', array(
      'sequential' => 1,
      'payment_processor_type_id' => "GoCardless",
      'name' => "GoCardless",
      'description' => "Set up by test script",
      'signature' => "mock_webhook_key",
      'is_active' => 1,
      'is_test' => 1,
      'user_name' => "fake_test_api_key",
      'payment_instrument_id' => "direct_debit_gc",
      'domain_id' => 1,
    ));
    // We need a live one, too.
    $result = civicrm_api3('PaymentProcessor', 'create', array(
      'sequential' => 1,
      'payment_processor_type_id' => "GoCardless",
      'name' => "GoCardless",
      'signature' => "this is no the webhook key you are looking fo",
      'description' => "Set up by test script",
      'is_active' => 1,
      'is_test' => 0,
      'user_name' => "fake_live_api_key",
      'payment_instrument_id' => "direct_debit_gc",
      'domain_id' => 1,
    ));

    // Map contribution statuses to values.
    // @todo I think there's some nicer way to deal with this??
    $result = civicrm_api3('OptionValue', 'get', array(
      'sequential' => 1,
      'return' => array("value", "name"),
      'option_group_id' => "contribution_status",
    ));
    foreach($result['values'] as $opt) {
      $this->contribution_status_map[$opt['name']] = $opt['value'];
    }

  }

  public function tearDown() {
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
    $api_prophecy = $this->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(TRUE, $api_prophecy->reveal());
    $redirect_flows = $this->prophesize('\\GoCardlessPro\\Services\\RedirectFlowsService');
    $api_prophecy->redirectFlows()->willReturn($redirect_flows->reveal());
    $redirect_flows->create(Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"redirect_url":"https://gocardless.com/somewhere","id":"RE1234"}'));

    $pp = CRM_GoCardlessUtils::getPaymentProcessor(TRUE);

    $obj = new CRM_Core_Payment_GoCardless('test', $pp);
    $params = [
      'qfKey' => 'aabbccdd',
      'contactID' => 111,
      'description' => 'test contribution',
      'contributionID' => 222,
      'contributionRecurID' => 333,
      'entryURL' => 'http://example.com/somwhere',
    ];
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
   * Check a transfer checkout works.
   *
   * This actually results in a redirect, but all the work that goes into that
   * is in a separate function, so we can test that.
   */
  public function testTransferCheckoutCompletes() {
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
        'financial_type_id' => 1, // Donation
        'total_amount' => 1,
        'contact_id' => $contact['id'],
        'contribution_recur_id' => $recur['id'],
        'contribution_status_id' => "Pending",
        'is_test' => 1,
      ));

    // Mock the GC API.
    $api_prophecy = $this->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(TRUE, $api_prophecy->reveal());

    $redirect_flows_service = $this->prophesize('\\GoCardlessPro\\Services\\RedirectFlowsService');
    $api_prophecy->redirectFlows()->willReturn($redirect_flows_service->reveal());
    $redirect_flows_service->complete(Argument::any(), Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"redirect_url":"https://gocardless.com/somewhere","id":"RE1234","links":{"mandate":"MANDATEID"}}'));

    $subscription_service = $this->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
    $api_prophecy->subscriptions()->willReturn($subscription_service->reveal());
    $subscription_service->create(Argument::any())
      ->willReturn(json_decode('{"start_date":"2016-10-08","id":"subscriptionid"}'));
    // Params are usually assembled by the civicrm_buildForm hook.
    $params = [
      'test_mode' => TRUE,
      'redirect_flow_id' => 'RE1234',
      'session_token' => 'aabbccdd',
      'contactID' => $contact['id'],
      'description' => 'test contribution',
      'contributionID' => $contrib['id'],
      'contributionRecurID' => $recur['id'],
      'entryURL' => 'http://example.com/somwhere',
    ];
    CRM_GoCardlessUtils::completeRedirectFlow($params);

    // Now test the contributions were updated.
    $result = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $recur['id']]);
    $this->assertEquals(5, $result['contribution_status_id']);
    $this->assertEquals('subscriptionid', $result['invoice_id']);
    $this->assertEquals('2016-10-08 00:00:00', $result['start_date']);
    $result = civicrm_api3('Contribution', 'getsingle', ['id' => $contrib['id']]);
    $this->assertEquals('subscriptionid', $result['invoice_id']);
    $this->assertEquals('2016-10-08 00:00:00', $result['receive_date']);
    $this->assertEquals(2, $result['contribution_status_id']);

  }

  /**
   * Check missing signature throws InvalidArgumentException.
   *
   * @expectedException InvalidArgumentException
   * @expectedExceptionMessage Unsigned API request.
   */
  public function testWebhookMissingSignature() {
    $controller = new CRM_GoCardless_Page_Webhook();
    $controller->parseWebhookRequest([], '');
  }
  /**
   * Check wrong signature throws InvalidArgumentException.
   *
   * @expectedException InvalidArgumentException
   * @expectedExceptionMessage Invalid signature in request.
   */
  public function testWebhookWrongSignature() {
    $controller = new CRM_GoCardless_Page_Webhook();
    $controller->parseWebhookRequest(["Webhook-Signature" => 'foo'], 'bar');
  }
  /**
   * Check empty body throws InvalidArgumentException.
   *
   * @expectedException InvalidArgumentException
   * @expectedExceptionMessage Invalid or missing data in request.
   */
  public function testWebhookMissingBody() {
    $controller = new CRM_GoCardless_Page_Webhook();
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
    $controller = new CRM_GoCardless_Page_Webhook();
    $body = 'This is not json.';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
  }
  /**
   * Check events extracted from webhook.
   *
   */
  public function testWebhookParse() {
    $controller = new CRM_GoCardless_Page_Webhook();
    $body = '{"events":[
      {"id":"EV1","resource_type":"payments","action":"confirmed"},
      {"id":"EV2","resource_type":"payments","action":"failed"},
      {"id":"EV3","resource_type":"payments","action":"something we do not handle"},
      {"id":"EV4","resource_type":"subscription","action":"cancelled"},
      {"id":"EV5","resource_type":"subscription","action":"finished"},
      {"id":"EV6","resource_type":"subscription","action":"something we do not handle"},
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

    $contact = civicrm_api3('Contact', 'create', array(
        'sequential' => 1,
        'contact_type' => "Individual",
        'first_name' => "Wilma",
        'last_name' => "Flintstone",
    ));
    $recur = civicrm_api3('ContributionRecur', 'create', array(
          'sequential' => 1,
          'contact_id' => $contact['id'],
          'financial_type_id' => 1, // Donation
          'frequency_interval' => 1,
          'amount' => 1,
          'frequency_unit' => "month",
          'start_date' => "2016-10-01",
          'is_test' => 1,
          'contribution_status_id' => "In Progress",
          'invoice_id' => 'SUBSCRIPTION_ID'
        ));
    $contrib = civicrm_api3('Contribution', 'create', array(
        'sequential' => 1,
        'financial_type_id' => 1, // Donation
        'total_amount' => 1,
        'contact_id' => $contact['id'],
        'contribution_recur_id' => $recur['id'],
        'contribution_status_id' => "Pending",
        'receive_date' => '2016-10-01',
        'is_test' => 1,
      ));

    // Mock webhook input data.
    $controller = new CRM_GoCardless_Page_Webhook();
    $body = '{"events":[
      {"id":"EV1","resource_type":"payments","action":"confirmed","links":{"payment":"PAYMENT_ID"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock GC API.
    $api_prophecy = $this->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(TRUE, $api_prophecy->reveal());
    // First the webhook will load the payment, so mock this.
    $payments_service = $this->prophesize('\\GoCardlessPro\\Services\\PaymentsService');
    $api_prophecy->payments()->willReturn($payments_service->reveal());
    $payments_service->get('PAYMENT_ID')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
        "id":"PAYMENT_ID",
          "status":"confirmed",
          "charge_date":"2016-10-02",
          "amount":123,
          "links":{"subscription":"SUBSCRIPTION_ID"}
        }'));

    // Now trigger webhook.
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
    $controller->processWebhookEvents();

    // Now check the changes have been made.
    $result = civicrm_api3('Contribution', 'getsingle', ['id' => $contrib['id']]);
    $this->assertEquals('2016-10-02 00:00:00', $result['receive_date']);
    $this->assertEquals('SUBSCRIPTION_ID', $result['invoice_id']);
    $this->assertEquals(1.23, $result['total_amount']);
    $this->assertEquals('PAYMENT_ID', $result['trxn_id']);
    $this->assertEquals($this->contribution_status_map['Completed'], $result['contribution_status_id']);

  }
  /**
   * A payment confirmation should create a new contribution.
   *
   */
  public function testWebhookPaymentConfirmationSubsequent() {

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
          'financial_type_id' => 1, // Donation
          'amount' => 1,
          'frequency_unit' => "month",
          'start_date' => "2016-10-01",
          'is_test' => 1,
          'contribution_status_id' => "In Progress",
          'invoice_id' => 'SUBSCRIPTION_ID'
        ));

    // Mock that we have had one completed payment.
    $contrib = civicrm_api3('Contribution', 'create', array(
        'sequential' => 1,
        'total_amount' => 1,
        'financial_type_id' => 1, // Donation
        'contact_id' => $contact['id'],
        'contribution_recur_id' => $recur['id'],
        'contribution_status_id' => "Completed",
        'receive_date' => '2016-10-01',
        'is_test' => 1,
        'invoice_id' => 'SUBSCRIPTION_ID'
      ));

    // Mock webhook input data.
    $controller = new CRM_GoCardless_Page_Webhook();
    $body = '{"events":[
      {"id":"EV1","resource_type":"payments","action":"confirmed","links":{"payment":"PAYMENT_ID"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock GC API.
    $api_prophecy = $this->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(TRUE, $api_prophecy->reveal());
    // First the webhook will load the payment, so mock this.
    $payments_service = $this->prophesize('\\GoCardlessPro\\Services\\PaymentsService');
    $api_prophecy->payments()->willReturn($payments_service->reveal());
    $payments_service->get('PAYMENT_ID')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
        "id":"PAYMENT_ID",
          "status":"confirmed",
          "charge_date":"2016-10-02",
          "amount":123,
          "links":{"subscription":"SUBSCRIPTION_ID"}
        }'));

    // Now trigger webhook.
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
    $controller->processWebhookEvents();

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
    $this->assertEquals('SUBSCRIPTION_ID', $contrib['invoice_id']);
    $this->assertEquals(1.23, $contrib['total_amount']);
    $this->assertEquals('PAYMENT_ID', $contrib['trxn_id']);
    $this->assertEquals($this->contribution_status_map['Completed'], $contrib['contribution_status_id']);

  }
  /**
   * A payment confirmation webhook that is out of date.
   *
   * @expectedException CRM_GoCardless_WebhookEventIgnoredException
   * @expectedExceptionMessage Webhook out of date
   */
  public function testWebhookOutOfDate() {

    $controller = new CRM_GoCardless_Page_Webhook();

    // Mock GC API.
    $api_prophecy = $this->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(FALSE, $api_prophecy->reveal());
    // First the webhook will load the payment, so mock this.
    $payments_service = $this->prophesize('\\GoCardlessPro\\Services\\PaymentsService');
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
    $event = json_decode(json_encode([ 'links' => [ 'payment' => 'PAYMENT_ID' ]]));
    $controller->getAndCheckPayment($event, 'confirmed'); // Calling with different status to that which will be fetched from API.
  }

  /**
   * A subscription cancelled webhook that is out of date.
   *
   * @expectedException CRM_GoCardless_WebhookEventIgnoredException
   * @expectedExceptionMessage Webhook out of date
   */
  public function testWebhookOutOfDateSubscription() {


    // Mock GC API.
    $api_prophecy = $this->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(FALSE, $api_prophecy->reveal());
    // First the webhook will load the subscription, so mock this.
    $subscription_service = $this->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
    $api_prophecy->subscriptions()->willReturn($subscription_service->reveal());
    $subscription_service->get('SUBSCRIPTION_ID')
      ->shouldBeCalled()
      ->willReturn(json_decode('{
        "id":"SUBSCRIPTION_ID",
        "status":"cancelled"
        }'));

    $event = json_decode('{"links":{"subscription":"SUBSCRIPTION_ID"}}');
    $controller = new CRM_GoCardless_Page_Webhook();
    $controller->getAndCheckSubscription($event, 'complete'); // Calling with different status to that which will be fetched from API.
  }

  /**
   * A payment confirmation webhook event that does not have a subscription
   * should be ignored.
   *
   * @expectedException CRM_GoCardless_WebhookEventIgnoredException
   * @expectedExceptionMessage Ignored payment that does not belong to a subscription.
   */
  public function testWebhookPaymentWithoutSubscriptionIgnored() {

    $controller = new CRM_GoCardless_Page_Webhook();

    // Mock GC API.
    $api_prophecy = $this->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(FALSE, $api_prophecy->reveal());
    // First the webhook will load the payment, so mock this.
    $payments_service = $this->prophesize('\\GoCardlessPro\\Services\\PaymentsService');
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
    $controller->getAndCheckPayment($event, 'confirmed'); // Calling with different status to that which will be fetched from API.
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
          'financial_type_id' => 1, // Donation
          'amount' => 1,
          'frequency_unit' => "month",
          'start_date' => "2016-10-01",
          'is_test' => 1,
          'contribution_status_id' => "In Progress",
          'invoice_id' => 'SUBSCRIPTION_ID'
        ));

    // Mark this contrib as Incomplete - this is the case that the thing's just
    // been set up by a Contribution Page.
    $contrib = civicrm_api3('Contribution', 'create', array(
        'sequential' => 1,
        'total_amount' => 1,
        'financial_type_id' => 1, // Donation
        'contact_id' => $contact['id'],
        'contribution_recur_id' => $recur['id'],
        'contribution_status_id' => "Pending",
        'receive_date' => '2016-10-01',
        'is_test' => 1,
      ));

    // Mock webhook input data.
    $controller = new CRM_GoCardless_Page_Webhook();
    $body = '{"events":[
      {"id":"EV1","resource_type":"subscription","action":"cancelled","links":{"subscription":"SUBSCRIPTION_ID"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock GC API.
    $api_prophecy = $this->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(TRUE, $api_prophecy->reveal());
    // First the webhook will load the subscription, so mock this.
    $subscription_service = $this->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
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
    $controller->processWebhookEvents();

    // Now check the changes have been made to the original contribution.
    $contrib = civicrm_api3('Contribution', 'getsingle', [
      'contribution_recur_id' => $recur['id'],
      'is_test' => 1,
      ]);
    $this->assertEquals($this->contribution_status_map['Cancelled'], $contrib['contribution_status_id']);

    // Now check the changes have been made to the recurring contribution.
    $contrib = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $recur['id']]);
    $this->assertEquals('2016-10-02 00:00:00', $contrib['end_date']);
    $this->assertEquals('SUBSCRIPTION_ID', $contrib['invoice_id']);
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
          'financial_type_id' => 1, // Donation
          'amount' => 1,
          'frequency_unit' => "month",
          'start_date' => "2016-10-01",
          'is_test' => 1,
          'contribution_status_id' => "In Progress",
          'invoice_id' => 'SUBSCRIPTION_ID'
        ));

    // Mark this contrib as Incomplete - this is the case that the thing's just
    // been set up by a Contribution Page.
    $contrib = civicrm_api3('Contribution', 'create', array(
        'sequential' => 1,
        'total_amount' => 1,
        'financial_type_id' => 1, // Donation
        'contact_id' => $contact['id'],
        'contribution_recur_id' => $recur['id'],
        'contribution_status_id' => "Pending",
        'receive_date' => '2016-10-01',
        'is_test' => 1,
      ));

    // Mock webhook input data.
    $controller = new CRM_GoCardless_Page_Webhook();
    $body = '{"events":[
      {"id":"EV1","resource_type":"subscription","action":"finished","links":{"subscription":"SUBSCRIPTION_ID"}}
      ]}';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');

    // Mock GC API.
    $api_prophecy = $this->prophesize('\\GoCardlessPro\\Client');
    CRM_GoCardlessUtils::setApi(TRUE, $api_prophecy->reveal());
    // First the webhook will load the subscription, so mock this.
    $subscription_service = $this->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
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
    $controller->processWebhookEvents();

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
    $this->assertEquals('SUBSCRIPTION_ID', $contrib['invoice_id']);
    $this->assertEquals($this->contribution_status_map['Completed'], $contrib['contribution_status_id']);

  }
  /**
   * Return a fake GoCardless payment processor.
   */
  protected function getMockPaymentProcessor() {

  }

}
