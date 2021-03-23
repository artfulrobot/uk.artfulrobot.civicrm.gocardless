<?php

class CRM_GoCardless_Hook {

  /**
   * This hook allows to override gocardless subscription process or
   * parameters.
   *
   * @param array $deets with the following keys
   *
   * - test_mode bool.
   * - session_token string used in creating the flow with getRedirectFlow().
   * - redirect_flow_id
   * - description
   * - payment_processor_id
   * - interval_unit yearly/monthly/weekly
   * - amount (in GBP, e.g. 10.50)
   * - installments (optional positive integer number of payments to take)
   * - and additional recurring details like interval, unit, amount,
   *   installments
   *
   * @param array $result variable to put results in when overriding
   *   completeRedirectFlowWithGoCardless()
   * @param bool $callMain set this to false if
   *   completeRedirectFlowWithGoCardless() should be skipped.
   *
   * @return mixed
   *   Ignored value.
   */
  public static function handleRedirectFlowWithGoCardless($deets, &$result, &$callMain) {
    $null = NULL;
    return CRM_Utils_Hook::singleton()->invoke([
      'deets',
      'result',
      'callMain',
    ],
      $deets,
      $result,
      $callMain,
      $null,
      $null,
      $null,
      'civicrm_handleRedirectFlowWithGoCardless');
  }

}
