<?php

use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * Job.Gocardlessfailabandoned API Test Case
 * This is a generic test class implemented with PHPUnit.
 * @group headless
 */
class api_v3_Job_GocardlessfailabandonedTest extends PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {
  use \Civi\Test\Api3TestTrait;
  /**
   * Holds test mode payment processor.
   *
   * @var array
   */
  public $test_mode_payment_processor;
  /**
   * @var int */
  public $dummy_payment_processor_id;
  /**
   * @var array Holds a map of name -> value for contribution statuses */
  protected $contribution_status_map;
  /**
   * @var int contact id */
  protected $contact_id;
  /**
   * @var int contrib recur id */
  protected $contribution_recur_id;

  /**
   * @var int contrib id */
  protected $contribution_id;

  /**
   * Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
   * See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
   */
  public function setUpHeadless() {
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  /**
   * The setup() method is executed before the test is executed (optional).
   */
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

    // Create a dummy one.
    $result = civicrm_api3('PaymentProcessor', 'create', array(
      'sequential' => 1,
      'payment_processor_type_id' => "Dummy",
      'name' => "Dummy",
      'description' => "Set up by test script",
      'is_active' => 1,
      'is_test' => 0,
      'domain_id' => 1,
    ));
    $this->dummy_payment_processor_id = $result['id'];

    // Map contribution statuses to values.
    $this->contribution_status_map = array_flip(CRM_Contribute_BAO_ContributionRecur::buildOptions('contribution_status_id', 'validate'));

    $contact = civicrm_api3('Contact', 'create', array(
      'sequential' => 1,
      'contact_type' => "Individual",
      'first_name' => "Wilma",
      'last_name' => "Flintstone",
    ));
    $this->contact_id = $contact['id'];

    // Create a contrib recur record.
    $result = civicrm_api3('ContributionRecur', 'create', [
      'sequential'             => 1,
      'contact_id'             => $this->contact_id,
      'frequency_interval'     => 1,
      'amount'                 => 1,
      'frequency_unit'         => "month",
      'start_date'             => "2019-06-01",
      'is_test'                => 1,
      'contribution_status_id' => "Pending",
      'payment_processor_id'   => $this->test_mode_payment_processor['id'],
    ]);
    $this->contribution_recur_id = $result['id'];

    // Create the pending contribution.
    $result = civicrm_api3('Contribution', 'create', [
      'sequential'             => 1,
      'contact_id'             => $this->contact_id,
    // Donation
      'financial_type_id'      => 1,
      'total_amount'           => 1,
      'contribution_recur_id'  => $this->contribution_recur_id,
      'amount'                 => 1,
      'receive_date'           => "2019-06-01",
      'is_test'                => 1,
      'contribution_status_id' => "Pending",
    ]);
    $this->contribution_id = $result['id'];
  }

  /**
   * The tearDown() method is executed after the test was executed (optional)
   * This can be used for cleanup.
   */
  public function tearDown() {
    parent::tearDown();
  }

  /**
   * Test old ones are marked as failed.
   */
  public function testMarksOldAsFailed() {

    // Ages ago.
    $this->setModifiedDate('1 Jan 2000');
    $result = civicrm_api3('Job', 'Gocardlessfailabandoned');
    $this->assertEquals(['contribution_recur_ids' => [$this->contribution_recur_id]], $result['values']);

    // Check that the thing is now Failed.
    $this->assertCrStatus('Failed', 'Should have set CR to Failed');
    $this->assertCStatus('Cancelled', 'Should have cancelled the Contribution');
  }

  /**
   * Test new ones are not.
   */
  public function testLeavesRecentAlone() {

    // Right now.
    $this->setModifiedDate('now');
    $result = civicrm_api3('Job', 'Gocardlessfailabandoned');
    $this->assertEquals(['contribution_recur_ids' => []], $result['values']);

    // Check that the thing is still Pending
    $this->assertCrStatus('Pending', 'Should have left a recent record alone');
    $this->assertCStatus('Pending', 'Should have left the Contribution alone');
  }

  /**
   * Test that it does not touch things belonging to other payment processors.
   */
  public function testLeavesOthersAlone() {

    // Ages ago.
    $this->setModifiedDate('2000-01-01');
    // Change the payment processor.
    $result = civicrm_api3('ContributionRecur', 'create', [
      'id'                   => $this->contribution_recur_id,
      'contact_id'           => $this->contact_id,
      'payment_processor_id' => $this->dummy_payment_processor_id,
    ]);

    $result = civicrm_api3('Job', 'Gocardlessfailabandoned');
    $this->assertEquals(['contribution_recur_ids' => []], $result['values']);

    // Check that the thing is still Pending
    $this->assertCrStatus('Pending', 'Should have left a recent record alone');
    $this->assertCStatus('Pending', 'Should have left the Contribution alone');
  }

  /**
   * Test that it does not touch things belonging to other payment processors.
   */
  public function testLeavesInProgressAlone() {

    // Ages ago.
    $this->setModifiedDate('2000-01-01');

    foreach ($this->contribution_status_map as $words => $status_id) {
      if ($words === 'Pending') {
        continue;
      }

      // Change the status
      $result = civicrm_api3('ContributionRecur', 'create', [
        'id'                     => $this->contribution_recur_id,
        'contact_id'             => $this->contact_id,
        'contribution_status_id' => $status_id,
      ]);

      $result = civicrm_api3('Job', 'Gocardlessfailabandoned');
      $this->assertEquals(['contribution_recur_ids' => []], $result['values'], "Expected it to not affect '$words' contribution statuses");
      $this->assertCStatus('Pending', 'Should have left the Contribution alone');
    }

  }

  protected function assertCrStatus($status, $message = NULL) {
    $result = civicrm_api3('ContributionRecur', 'getsingle', ['id' => $this->contribution_recur_id]);
    $this->assertEquals($this->contribution_status_map[$status], $result['contribution_status_id'], $message);
  }

  protected function assertCStatus($status, $message = NULL) {
    $result = civicrm_api3('Contribution', 'getsingle', ['id' => $this->contribution_id]);
    $sm = array_flip($this->contribution_status_map);
    $this->assertEquals($status, $sm[$result['contribution_status_id']], $message);
  }

  /**
   * SQL to update modified date.
   */
  protected function setModifiedDate($date) {
    $sql = 'UPDATE civicrm_contribution_recur SET modified_date = %1 WHERE id = %2';
    $params = [
      1 => [date('Y-m-d H:i:s', strtotime($date)), 'String'],
      2 => [$this->contribution_recur_id, 'Integer'],
    ];
    CRM_Core_DAO::executeQuery($sql, $params);
  }

}
