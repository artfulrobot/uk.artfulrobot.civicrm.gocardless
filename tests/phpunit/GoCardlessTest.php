<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

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
      'is_active' => 1,
      'is_test' => 1,
      'user_name' => "fake_api_key",
      'payment_instrument_id' => "direct_debit_gc",
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
    $pp = CRM_GoCardlessUtils::getPaymentProcessor();
    $obj = new CRM_Core_Payment_GoCardless('test', $pp);
    $params = [];
    $url = $obj->doTransferCheckoutWorker($params, 'contribute');
    $this->assertType('string', $url);
  }

  /**
   * Return a fake GoCardless payment processor.
   */
  protected function getMockPaymentProcessor() {

  }

}
