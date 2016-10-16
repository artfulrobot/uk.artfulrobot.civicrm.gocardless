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
    $api_prophecy->redirectFlows()
      ->will(function() use ($redirect_flows) {
        return $redirect_flows->reveal();
      });
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
    $api_prophecy->redirectFlows()
      ->will(function() use ($redirect_flows_service) {
        return $redirect_flows_service->reveal();
      });
    $redirect_flows_service->complete(Argument::any(), Argument::any())
      ->shouldBeCalled()
      ->willReturn(json_decode('{"redirect_url":"https://gocardless.com/somewhere","id":"RE1234","links":{"mandate":"MANDATEID"}}'));

    $subscription_service = $this->prophesize('\\GoCardlessPro\\Services\\SubscriptionsService');
    $api_prophecy->subscriptions()
      ->will(function() use ($subscription_service) {
        return $subscription_service->reveal();
      });
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
    $controller = new CRM_Gocardlessdd_Page_Webhook();
    $controller->parseWebhookRequest([], '');
  }
  /**
   * Check wrong signature throws InvalidArgumentException.
   *
   * @expectedException InvalidArgumentException
   * @expectedExceptionMessage Invalid signature in request.
   */
  public function testWebhookWrongSignature() {
    $controller = new CRM_Gocardlessdd_Page_Webhook();
    $controller->parseWebhookRequest(["Webhook-Signature" => 'foo'], 'bar');
  }
  /**
   * Check empty body throws InvalidArgumentException.
   *
   * @expectedException InvalidArgumentException
   * @expectedExceptionMessage Invalid or missing data in request.
   */
  public function testWebhookMissingBody() {
    $controller = new CRM_Gocardlessdd_Page_Webhook();
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
    $controller = new CRM_Gocardlessdd_Page_Webhook();
    $body = 'This is not json.';
    $calculated_signature = hash_hmac("sha256", $body, 'mock_webhook_key');
    $controller->parseWebhookRequest(["Webhook-Signature" => $calculated_signature], $body);
  }
  /**
   * Check events extracted from webhook.
   *
   */
  public function testWebhookParse() {
    $controller = new CRM_Gocardlessdd_Page_Webhook();
    $body = '{"events":[
      {"id":"EV1","resource_type":"payments","action":"confirmed"},
      {"id":"EV2","resource_type":"payments","action":"failed"},
      {"id":"EV3","resource_type":"payments","action":"something we do not handle"},
      {"id":"EV4","resource_type":"mandate","action":"expired"},
      {"id":"EV5","resource_type":"mandate","action":"disabled"},
      {"id":"EV6","resource_type":"mandate","action":"something we do not handle"},
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
   * Return a fake GoCardless payment processor.
   */
  protected function getMockPaymentProcessor() {

  }

}
