<?php

class CRM_GoCardless_Hook {

  /**
   * This hook allows to override gocardless subscription process or
   * parameters.
   *
   * @param array $details with the following keys
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
   *
   * @return mixed
   *   Ignored value.
   */
  public static function handleRedirectFlowWithGoCardless($details, &$result) {
    $null = NULL;
    return CRM_Utils_Hook::singleton()->invoke([
      'details',
      'result'
    ],
      $details,
      $result,
      $null,
      $null,
      $null,
      $null,
      'civicrm_handleRedirectFlowWithGoCardless');
  }

}
