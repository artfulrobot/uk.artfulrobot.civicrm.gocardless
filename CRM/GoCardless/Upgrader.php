<?php

/**
 * Collection of upgrade steps.
 */
class CRM_GoCardless_Upgrader extends CRM_GoCardless_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Example: Run an external SQL script when the module is installed.
   */
  public function install() {
    /**
     * Helper function for creating data structures.
     *
     * @param string $entity - name of the API entity.
     * @param Array $params_min parameters to use for search.
     * @param Array $params_extra these plus $params_min are used if a create call
     *              is needed.
     */
    $get_or_create = function ($entity, $params_min, $params_extra) {
      $params_min += ['sequential' => 1];
      $result = civicrm_api3($entity, 'get', $params_min);
      if (!$result['count']) {
        // Couldn't find it, create it now.
        $result = civicrm_api3($entity, 'create', $params_extra + $params_min);
      }
      return $result['values'][0];
    };

    // We need a PaymentProcessorType, which in turn needs a Payment Instrument, which in turn needs a financial account.

    // Find a "GoCardless Account" - we could have used the normally pre-existing
    // Payment Processor Account, but actually it will be a separate account.
    $financial_account = $get_or_create('FinancialAccount', [
      'financial_account_type_id' => 'Asset',
      'name' => 'GoCardless Account',
      'description' => 'Funds held by GoCardless from which they will make pay outs to your account as per their policy.',
    ], []);

    // We need a payment instrument known as direct_debit_gc.
    $payment_instrument = $get_or_create('OptionValue',
      [
        'option_group_id' => "payment_instrument",
        'name' => "direct_debit_gc",
      ],
      [
        'label' => ts("GoCardless Direct Debit"),
        'financial_account_id' => $financial_account['id'],
      ]);
    $payment_instrument_id = $payment_instrument['value'];

    // Now create the PaymentProcessorType.
    $get_or_create('PaymentProcessorType',
      [
        'name' => 'GoCardless',
        'title' => 'GoCardless',
        'class_name' => 'Payment_GoCardless',
        'billing_mode' => 4,
        'is_recur' => 1,
      ],
      [
        'is_active' => 1,
        'is_default' => 0,
        'user_name_label' => 'API Access Token',
        'signature_label' => 'Webhook Secret',
        'url_api_default' => 'https://api.gocardless.com/',
        'url_api_test_default' => 'https://api-sandbox.gocardless.com/',
        'billing_mode' => 4,
        'is_recur' => 1,
        'payment_type' => $payment_instrument_id,
      ]);
  }

  /**
   * Example: Work with entities usually not available during the install step.
   *
   * This method can be used for any post-install tasks. For example, if a step
   * of your installation depends on accessing an entity that is itself
   * created during the installation (e.g., a setting or a managed entity), do
   * so here to avoid order of operation problems.
   *
   * public function postInstall() {
   * $customFieldId = civicrm_api3('CustomField', 'getvalue', array(
   * 'return' => array("id"),
   * 'name' => "customFieldCreatedViaManagedHook",
   * ));
   * civicrm_api3('Setting', 'create', array(
   * 'myWeirdFieldSetting' => array('id' => $customFieldId, 'weirdness' => 1),
   * ));
   * }
   *
   * /**
   * Example: Run an external SQL script when the module is uninstalled.
   *
   * public function uninstall() {
   * $this->executeSqlFile('sql/myuninstall.sql');
   * }
   *
   * /**
   * Example: Run a simple query when a module is enabled.
   *
   * public function enable() {
   * CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 1 WHERE bar = "whiz"');
   * }
   *
   * /**
   * Example: Run a simple query when a module is disabled.
   *
   * public function disable() {
   * CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 0 WHERE bar = "whiz"');
   * }
   *
   * /**
   * Example: Run a couple simple queries.
   *
   * @return TRUE on success
   * @throws Exception
   *
   * public function upgrade_4200() {
   * $this->ctx->log->info('Applying update 4200');
   * CRM_Core_DAO::executeQuery('UPDATE foo SET bar = "whiz"');
   * CRM_Core_DAO::executeQuery('DELETE FROM bang WHERE willy = wonka(2)');
   * return TRUE;
   * } // */


  /**
   * Example: Run an external SQL script.
   *
   * @return TRUE on success
   * @throws Exception
   * public function upgrade_4201() {
   * $this->ctx->log->info('Applying update 4201');
   * // this path is relative to the extension base dir
   * $this->executeSqlFile('sql/upgrade_4201.sql');
   * return TRUE;
   * } // */


  /**
   * Example: Run a slow upgrade process by breaking it up into smaller chunk.
   *
   * @return TRUE on success
   * @throws Exception
   * public function upgrade_4202() {
   * $this->ctx->log->info('Planning update 4202'); // PEAR Log interface
   *
   * $this->addTask(E::ts('Process first step'), 'processPart1', $arg1, $arg2);
   * $this->addTask(E::ts('Process second step'), 'processPart2', $arg3, $arg4);
   * $this->addTask(E::ts('Process second step'), 'processPart3', $arg5);
   * return TRUE;
   * }
   * public function processPart1($arg1, $arg2) { sleep(10); return TRUE; }
   * public function processPart2($arg3, $arg4) { sleep(10); return TRUE; }
   * public function processPart3($arg5) { sleep(10); return TRUE; }
   * // */


  /**
   * Example: Run an upgrade with a query that touches many (potentially
   * millions) of records by breaking it up into smaller chunks.
   *
   * @return TRUE on success
   * @throws Exception
   * public function upgrade_4203() {
   * $this->ctx->log->info('Planning update 4203'); // PEAR Log interface
   *
   * $minId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(min(id),0) FROM civicrm_contribution');
   * $maxId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(max(id),0) FROM civicrm_contribution');
   * for ($startId = $minId; $startId <= $maxId; $startId += self::BATCH_SIZE) {
   * $endId = $startId + self::BATCH_SIZE - 1;
   * $title = E::ts('Upgrade Batch (%1 => %2)', array(
   * 1 => $startId,
   * 2 => $endId,
   * ));
   * $sql = '
   * UPDATE civicrm_contribution SET foobar = whiz(wonky()+wanker)
   * WHERE id BETWEEN %1 and %2
   * ';
   * $params = array(
   * 1 => array($startId, 'Integer'),
   * 2 => array($endId, 'Integer'),
   * );
   * $this->addTask($title, 'executeSql', $sql, $params);
   * }
   * return TRUE;
   * } // */

  /**
   * We used to store the subscription ID in the trxn_id field, but as this is
   * not yet (in 5.19.2) passed into the cancelsubscription method, we'll start
   * using processor_id instead.
   *
   * @return TRUE on success
   * @throws Exception
   */
   public function upgrade_0001() {
     $this->ctx->log->info('Applying update 0001');
     // this path is relative to the extension base dir
     $this->executeSqlFile('sql/upgrade_0001.sql');
     return TRUE;
   }

}
